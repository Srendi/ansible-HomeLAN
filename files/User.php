<?php

class User {

	private $id = 1;
	private $username = "System";
	private $status = 0;
	private $class = 8;
	private $loggedIn = false;
	private $user;

	// Min 22 chars (for bcrypt salt)
	const PASSWORD_SALT = "fab8d4cfdc0d5b45ecced01cfec8af25";
	const COOKIE_SALT = "4b0098a2c3346106c399bb51ee7641b6";
	const EMAIL_SALT = "6f49ed443cd274aec28aaf7cff23eae1";
	const HASHED_EMAILS = true;
	const GIGABYTE_ON_SIGNUP = 25;

	const CLASS_EXTRA = 0;
	const CLASS_ACTOR = 1;
	const CLASS_MOVIE_STAR = 2;
	const CLASS_DIRECTOR = 3;
	const CLASS_PRODUCER = 4;
	const CLASS_UPLOADER = 6;
	const CLASS_VIP = 7;
	const CLASS_ADMIN = 8;

	public function __construct($db = null) {
		$this->db = $db;
	}

	public function getStatus() {
		$array = array();

		$userFields = array(
			'id',
			'inviteban',
			'enabled',
			'notifs',
			'avatar',
			'anonym',
			'anonymratio',
			'anonymicons',
			'avatars',
			'bonuspoang',
			'class',
			'coin',
			'crown',
			'css',
			'design',
			'donor',
			'downloaded',
			'doljuploader',
			'uploaded',
			'indexlist',
			'invites',
			'last_allbrowse',
			'last_browse',
			'last_ovrigtbrowse',
			'last_reqbrowse',
			'last_seriebrowse',
			'last_tvbrowse',
			'last_bevakabrowse',
			'lastreadnews',
			'leechbonus',
			'leechstart',
			'parkerad',
			'passkey',
			'pokal',
			'postsperpage',
			'reqslots',
			'skull',
			'title',
			'topicsperpage',
			'torrentsperpage',
			'tvvy',
			'uplLastReadCommentId',
			'username',
			'uploadban',
			'warned',
			'warneduntil',
			'search_sort',
			'section',
			'language',
			'p2p');
		$returnUserArray = array();
		foreach ($this->getUser() as $key => $value) {
			if (in_array($key, $userFields)) {
				$returnUserArray[$key] = $value;
			}
		}
		$returnUserArray["notifs"] = $returnUserArray["notifs"] ? explode(",", $returnUserArray["notifs"]) : [];

		$returnUserArray["newMessages"] = $this->getNewMessages();
		$returnUserArray["unreadFlashNews"] = $this->getUnreadFlashNews();
		$returnUserArray["unreadWatch"] = $this->getAmountUnreadWatch();
		if ($this->getUplLastReadCommentId() > 0) {
			$returnUserArray["unreadTorrentComments"] = $this->unreadTorrentComments();
		}
		$returnUserArray["currentGbSeed"] = $this->getCurrentGbSeed();
		$returnUserArray["pastDaysSeed"] = $this->getPastDaysSeed();

		if ($this->getClass() >= self::CLASS_ADMIN) {
			$returnUserArray["newReports"] = $this->getUnhandledReports();
			$returnUserArray["newAdminMessages"] = $this->getUnreadAdminMessages();
		}

		$array["user"] = $returnUserArray;
		$array["settings"]["serverTime"] = Helper::getDateWithTimezoneOffset();
		$array["settings"]["donatedAmount"] = $this->getDonatedAmount();
		return $array;
	}

	private function unreadTorrentComments() {
		$sth = $this->db->query("SELECT COUNT(*) FROM comments LEFT JOIN torrents ON torrents.id = comments.torrent WHERE torrents.owner = ".$this->getId()." AND comments.id > ".$this->getUplLastReadCommentId()." AND comments.user != " . $this->getId());
		$res = $sth->fetch();
		return $res[0];
	}

	private function getUnhandledReports() {
		$sth = $this->db->query("SELECT COUNT(*) FROM reports WHERE handledBy = 0");
		$res = $sth->fetch();
		return $res[0];
	}

	private function getUnreadAdminMessages() {
		$sth = $this->db->query("SELECT COUNT(*) FROM staffmessages WHERE answeredby = 0");
		$res = $sth->fetch();
		return $res[0];
	}

	private function getDonatedAmount() {
		$sth = $this->db->query("SELECT COALESCE(SUM(sum), 0) FROM donated WHERE status = 1");
		$res = $sth->fetch();
		return $res[0];
	}

	public function loginCheck(){
		if (isset($_COOKIE["uid"]) && isset($_COOKIE["pass"])) {
			$uid = (int)$_COOKIE["uid"];
			$sth = $this->db->prepare('SELECT * FROM users WHERE id = ? AND enabled = ?');
			$sth->execute(array($uid, "yes"));

			if ($arr = $sth->fetch(PDO::FETCH_ASSOC)) {
				if ($this->hashCookie($arr["passhash"], $arr["class"] >= User::CLASS_VIP) == $_COOKIE["pass"]) {
					$this->setPrivateVars($arr);
				} else {
					throw new Exception(L::get("COOKIE_MATCH_ERROR"), 401);
				}
			} else {
				throw new Exception(L::get("USER_NOT_FOUND_ERROR"), 401);
			}
		} else {
			throw new Exception(L::get("COOKIE_MISSING_ERROR"), 401);
		}
	}

	public function login($username, $password) {
		$loginAttempts = new LoginAttempts($this->db, $this);
		$loginAttempts->check();

		$sth = $this->db->prepare('SELECT * FROM users WHERE username = ?');
		$sth->bindParam(1, $username, PDO::PARAM_STR, 15);
		$sth->execute();

		if ($arr = $sth->fetch(PDO::FETCH_ASSOC)) {
			if (password_verify($password . User::PASSWORD_SALT, $arr["passhash"])) {

				if ($arr["enabled"] == "no") {
					$loginAttempts->create(array("username" => $username, "password" => $password, "uid" => $arr["id"]));
					throw new Exception(L::get("USER_DISABLED", [$arr["secret"]]), 401);
				}

				setcookie("uid", $arr["id"], time()+31556926, "/");
				setcookie("pass", $this->hashCookie($arr["passhash"], $arr["class"] >= User::CLASS_VIP), time()+31556926, "/");

				$this->setPrivateVars($arr);
			} else {
				$loginAttempts->create(array("username" => $username, "password" => $password, "uid" => $arr["id"]));
				throw new Exception(L::get("USER_WRONG_CREDENTIALS"), 401);
			}
		} else {
			$loginAttempts->create(array("username" => $username, "password" => $password));
			throw new Exception(L::get("USER_WRONG_CREDENTIALS"), 401);
		}
	}

	public function recoverByPasskey($postdata) {
		$hashedEmail = $this->hashEmail($postdata["email"]);
		$sth = $this->db->prepare("SELECT id, username, enabled, secret FROM users WHERE email = ? AND passkey = ?");
		$sth->bindParam(1,	$hashedEmail,			PDO::PARAM_STR);
		$sth->bindParam(2,	$postdata["passkey"],	PDO::PARAM_STR);
		$sth->execute();
		$res = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$res) {
			throw new Exception(L::get("USER_EMAIL_PASSKEY_NO_MATCH"), 401);
		}

		if ($res["enabled"] == "no") {
			throw new Exception(L::get("USER_DISABLED", [$res["secret"]]), 401);
		}

		$newPassword = "temp" . rand(9, 99);
		$passhash = $this->hashPassword($newPassword);
		$this->db->query("UPDATE users SET passhash = " . $this->db->quote($passhash) . " WHERE id = " . $res["id"]);

		return array("username" => $res["username"], "newPassword" => $newPassword);
	}

	public function recoverByEmail($postdata) {
		$ip = $_SERVER["REMOTE_ADDR"];

		$recoverLog = new RecoveryLog($this->db);
		$recoverLog->check($ip);

		$hashedEmail = $this->hashEmail($postdata["email"]);

		$sth = $this->db->prepare("SELECT id, username, enabled, email, secret FROM users WHERE email = ?");
		$sth->bindParam(1,	$hashedEmail,	PDO::PARAM_STR);
		$sth->execute();
		$res = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$res) {
			throw new Exception(L::get("USER_EMAIL_NO_MATCH"), 401);
		}

		if ($res["enabled"] == "no") {
			throw new Exception(L::get("USER_DISABLED", [$res["secret"]]), 401);
		}

		$secret = md5(uniqid());
		$this->db->query("UPDATE users SET secret = " . $this->db->quote($secret) . " WHERE id = " . $res["id"]);

		$headers = "Reply-To: ".Config::NAME." <".Config::SITE_MAIL.">\r\n";
		$headers .= "Return-Path: ".Config::NAME." <".Config::SITE_MAIL.">\r\n";
		$headers .= "From: ".Config::NAME." <".Config::SITE_MAIL.">\r\n";
		$headers .= "Organization: ".Config::SITE_NAME."\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-type: text/plain; charset=utf-8\r\n";
		$headers .= "X-Mailer: PHP". phpversion() ."\r\n";

		$siteName = Config::SITE_NAME;
		$siteUrl = Config::SITE_URL;

		$body = L::get("RECOVER_EMAIL", [$siteUrl, $secret, $siteName]);

		mail($postdata["email"], Config::SITE_NAME . " password reset confirmation", $body, $headers, "-f" . Config::SITE_MAIL);

		$hostname = gethostbyaddr($ip);

		$recoverLog->create(array(
			"email" => $hashedEmail,
			"userid" => $res["id"],
			"ip" => $ip,
			"hostname" => $hostname
		));
	}

	public function gotRecoverByEmail($secret) {
		if (strlen($secret) !== 32) {
			throw new Exception(L::get("INVALID_RECOVERY_KEY"), 401);
		}
		$sth = $this->db->prepare("SELECT id, username, enabled, email, secret FROM users WHERE secret = ?");
		$sth->bindParam(1,	$secret,	PDO::PARAM_STR);
		$sth->execute();
		$res = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$res) {
			throw new Exception(L::get("RECOVERY_URL_ERROR"), 401);
		}

		$newPassword = "temp" . rand(9, 99);
		$passhash = $this->hashPassword($newPassword);
		$this->db->query("UPDATE users SET secret = '', passhash = " . $this->db->quote($passhash) . " WHERE id = " . $res["id"]);

		return array("username" => $res["username"], "newPassword" => $newPassword);
	}

	public function create($postdata) {
		$sth = $this->db->prepare("SELECT * FROM invites WHERE secret = ?");
		$sth->bindParam(1,	$postdata["inviteKey"],	PDO::PARAM_STR);
		$sth->execute();
		$invite = $sth->fetch(PDO::FETCH_ASSOC);

		$hashedEmail = $this->hashEmail($postdata["email"]);

		if (!$invite) {
			throw new Exception(L::get("INVITE_EXPIRED"), 412);
		}
		if (strlen($postdata["username"]) < 2 ) {
			throw new Exception(L::get("USER_NAME_TOO_SHORT"), 411);
		}
		if (strlen($postdata["username"]) > 14 ) {
			throw new Exception(L::get("USER_NAME_TOO_LONG"), 411);
		}
		if (!preg_match ('/^[a-z0-9][a-z0-9-_]+$/i', $postdata["username"]) ){
			throw new Exception(L::get("USER_NAME_CHARACTER_LIMITATION", ["A-Z 0-9"]), 412);
		}
		if (!$this->usernameIsAvailable($postdata["username"])) {
			throw new Exception(L::get("USER_NAME_OCCUPIED", [$postdata["username"]]), 409);
		}
		if (!preg_match ('/^[\w.-]+@([\w.-]+\.)+[a-z]{2,6}$/is', $postdata["email"])) {
			throw new Exception(L::get("INVALID_EMAIL"), 412);
		}
		if (!$this->emailIsAvailable($hashedEmail)) {
			throw new Exception(L::get("EMAIL_OCCUPIED"), 409);
		}
		if (strlen($postdata["password"]) < 6) {
			throw new Exception(L::get("PASSWORD_TOO_SHORT"), 411);
		}
		if ($postdata["password"] != $postdata["passwordAgain"] ) {
			throw new Exception(L::get("PASSWORD_NOT_MATCHING"), 412);
		}
		if (!in_array($postdata["language"], Config::$languages)) {
			$postdata["language"] = Config::DEFAULT_LANGUAGE;
		}

		switch ($postdata["format"]) {
			case 0:
				$indexlist = '2, 6'; // DVDR
				break;
			case 3:
				$indexlist = '11, 163'; // 1080p
				break;
			default:
				$indexlist = '1, 141'; // 720p
		}

		$age = (int) $postdata["age"];
		$gender = (int) $postdata["gender"];

		$sth = $this->db->query("SELECT id FROM news WHERE announce = 1 ORDER BY id DESC LIMIT 1");
		$res = $sth->fetch(PDO::FETCH_ASSOC);
		if (!$res) {
			$lastReadNews = 0;
		} else {
			$lastReadNews = $res["id"];
		}

		$added = date("Y-m-d H:i:s");
		$passhash = $this->hashPassword($postdata["password"]);
		$uploaded = 1073741824 * User::GIGABYTE_ON_SIGNUP;
		$leechEnd = date('Y-m-d H:i:s', time() + 86400); // 24h frree leech

		$sth = $this->db->prepare("INSERT INTO users (username, passhash, email, passkey, invited_by, indexlist, added, gender, alder, leechstart, uploaded, lastreadnews, last_access, anonym, language) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'yes', ?)");
		$sth->bindParam(1,	$postdata["username"],			PDO::PARAM_STR);
		$sth->bindParam(2,	$passhash,						PDO::PARAM_STR);
		$sth->bindParam(3,	$hashedEmail,					PDO::PARAM_STR);
		$sth->bindValue(4,	md5(uniqid()),					PDO::PARAM_STR);
		$sth->bindParam(5,	$invite["userid"],				PDO::PARAM_INT);
		$sth->bindParam(6,	$indexlist,						PDO::PARAM_INT);
		$sth->bindParam(7,	$added,							PDO::PARAM_STR);
		$sth->bindParam(8,	$gender,						PDO::PARAM_INT);
		$sth->bindParam(9,	$age,							PDO::PARAM_INT);
		$sth->bindParam(10,	$leechEnd,						PDO::PARAM_STR);
		$sth->bindParam(11,	$uploaded,						PDO::PARAM_INT);
		$sth->bindParam(12,	$lastReadNews,					PDO::PARAM_INT);
		$sth->bindParam(13,	$postdata["language"],			PDO::PARAM_STR);
		$sth->execute();
		$userId = $this->db->lastInsertId();

		$mailbox = new Mailbox($this->db);
		if ($invite["userid"] > 0) {
			$inviter = $this->get($invite["userid"]);
			$mailbox->sendSystemMessage($invite["userid"], L::get("INVITE_ACCEPTED", null, $inviter["language"]), L::get("INVITE_ACCEPTED_BODY", [$userId, $postdata["username"], $postdata["username"]], $inviter["language"]));
		}
		// Security checks

		$ip = $_SERVER["REMOTE_ADDR"];
		$hostname = gethostbyaddr($ip);

		$sth = $this->db->query("SELECT COUNT(*) FROM iplog WHERE ip = '".$ip."' AND userid != " . $userId);
		$res = $sth->fetch();
		$iplogHits = $res[0];

		$sth = $this->db->query("SELECT COUNT(*) FROM inlogg WHERE ip = '".$ip."' AND uid != " . $userId);
		$res = $sth->fetch();
		$loginAttemptsHits = $res[0];

		$sth = $this->db->query("SELECT COUNT(*) FROM emaillog WHERE email = '".$hashedEmail."' AND userid != " . $userId);
		$res = $sth->fetch();
		$emailLogHits = $res[0];

		$sth = $this->db->query("SELECT COUNT(*) FROM `inlogg` JOIN users ON inlogg.uid = users.id WHERE inlogg.ip = '".$ip."' AND enabled = 'no'");
		$res = $sth->fetch();
		$loginAttemptsWarningHits = $res[0];

		$sth = $this->db->query("SELECT COUNT(*) FROM `iplog` JOIN users ON iplog.userid = users.id WHERE iplog.ip = '".$ip."' AND enabled = 'no'");
		$res = $sth->fetch();
		$iplogWarningHits = $res[0];

		$ipHits = $iplogHits+$loginAttemptsHits;
		$warninLevel = $loginAttemptsWarningHits+$iplogWarningHits;


		$signups = new Signups($this->db, $this);

		$signups->create($userId, $ip, $hostname, $hashedEmail, $emailLogHits, $ipHits, $warninLevel);

		/* Zero means persistent invite url */
		if ($invite["userid"] != 0) {
			$this->db->query("DELETE FROM invites WHERE id = " . $invite["id"]);
		}
	}

	public function update($userId, $userData) {
		if ($this->getId() !== $userId && $this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$sth = $this->db->prepare('SELECT * FROM users WHERE id = ?');
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->execute();

		$user = $sth->fetch(PDO::FETCH_ASSOC);
		if (!$user) {
			throw new Exception(L::get("USER_NOT_EXIST"), 404);
		}

		$changedPassword = false;

		if ($userData["password"] != "") {
			if ($userData["password"] != $userData["passwordRepeat"]) {
				throw new Exception(L::get("NEW_PASSWORD_NOT_MATCHING"));
			}

			if ($this->getClass() >= self::CLASS_ADMIN || password_verify($userData["previousPassword"] . User::PASSWORD_SALT, $user["passhash"])) {
				$userData["passhash"] = $this->hashPassword($userData["password"], $user["added"]);
				$changedPassword = true;
			} else {
				throw new Exception(L::get("CURRENT_PASSWORD_WRONG"));
			}
		} else {
			$userData["passhash"] = $user["passhash"];
		}

		$userData["notifs"] = implode(",", $userData["notifs"]);
		$userData["warneduntil"] = $user["warneduntil"];

		if (!in_array($userData["language"], Config::$languages)) {
			$userData["language"] = Config::DEFAULT_LANGUAGE;
		}

		// Only uploaders and above can use user class mask feature
		if ($this->getClass() < User::CLASS_UPLOADER) {
			$userData["doljuploader"] = $user["doljuploader"];
		}

		if ($this->getClass() >= User::CLASS_ADMIN) {
			$adminlogs = new AdminLogs($this->db, $this);
			$mailbox = new Mailbox($this->db);

			if ($user["enabled"] != $userData["enabled"]) {
				if ($userData["enabled"] == "yes") {
					$adminlogs->create(L::get("ACCOUNT_ACTIVATED_ADMIN_LOG", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("ACCOUNT_ACTIVATED_LOG", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				} else {
					$adminlogs->create(L::get("ACCOUNT_DEACTIVATED_ADMIN_LOG", [$user["id"], $user["username"], $user["username"], $userData["secret"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("ACCOUNT_DEACTIVATED_LOG", [$this->getUsername(), $userData["secret"]], Config::DEFAULT_LANGUAGE));
				}
			}

			if ($user["class"] != $userData["class"]) {
				if ($user["class"] < $userData["class"]) {
					$statusChangeUser = L::get("STATUS_UPGRADED", null, $user["language"]);
					$statusChangeLog = L::get("STATUS_UPGRADED", null, Config::DEFAULT_LANGUAGE);
					$statusChangeTense = L::get("STATUS_WAS_UPGRADED", null, Config::DEFAULT_LANGUAGE);
				} else {
					$statusChangeUser = L::get("STATUS_DOWNGRADED", null, $user["language"]);
					$statusChangeLog = L::get("STATUS_DOWNGRADED", null, Config::DEFAULT_LANGUAGE);
					$statusChangeTense = L::get("STATUS_WAS_DOWNGRADED", null, Config::DEFAULT_LANGUAGE);
				}

				$newClass = Helper::getUserClassById($userData["class"]);
				$oldClass = Helper::getUserClassById($user["class"]);

				$mailbox->sendSystemMessage($user["id"], L::get("CLASS_CHANGED_SUBJECT", [ucfirst($statusChangeUser), $newClass], $user["language"]), L::get("CLASS_CHANGED_MESSAGE", [$statusChangeUser, $newClass], $user["language"]));
				$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("CLASS_CHANGED_COMMENT", [ucfirst($statusChangeLog), $oldClass, $newClass, $this->getUsername()], Config::DEFAULT_LANGUAGE));
				$adminlogs->create(L::get("CLASS_CHANGED_ADMINLOG", [$statusChangeTense, $user["id"], $user["username"], $user["username"], $oldClass, $newClass], Config::DEFAULT_LANGUAGE));

				$userData["doljuploader"] = $userData["class"];

				if ($userData["class"] >= self::CLASS_MOVIE_STAR) {
					$this->db->query('DELETE FROM iplog WHERE userid = ' . $user["id"]);
				}
			}

			if ($user["passkey"] != $userData["passkey"]) {
				$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("PASSKEY_RENEWED", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
			}

			if ($user["warned"] != $userData["warned"]) {
				if ($userData["warned"] == "yes") {
					$days = max(1, $userData["warnDays"]);
					$userData["warneduntil"] = date("Y-m-d H:i:s", time() + 86400 * $days);

					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("WARNED_USERLOG", [$days, $this->getUsername(), $userData["warnReason"]], Config::DEFAULT_LANGUAGE));
					$adminlogs->create(L::get("WARNED_ADMINLOG", [$user["id"], $user["username"], $user["username"], $days, $userData["warnReason"]], Config::DEFAULT_LANGUAGE));
					$mailbox->sendSystemMessage($user["id"], L::get("WARNED_PM_SUBJECT", null, $user["language"]), L::get("WARNED_PM_BODY", [$days, $userData["warnReason"]], $user["language"]));
				} else {
					$userData["warneduntil"] = "0000-00-00 00:00:00";
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("WARNING_REMOVED_USERLOG", [$this->getUsername()]));
					$adminlogs->create(L::get("WARNING_REMOVED_ADMINLOG", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$mailbox->sendSystemMessage($user["id"], L::get("WARNING_REMOVED_PM_SUBJECT", null, $user["language"]), L::get("WARNING_REMOVED_PM_BODY", null, $user["language"]));
				}
			}

			if ($user["uploadban"] != $userData["uploadban"]) {
				if ($userData["uploadban"] == 1) {
					$adminlogs->create(L::get("UPLOADBAN_ADDED", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("UPLOADBAN_ADDED_LOG", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				} else {
					$adminlogs->create(L::get("UPLOADBAN_REMOVED", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("UPLOADBAN_REMOVED_LOG", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				}
			}

			if ($user["inviteban"] != $userData["inviteban"]) {
				if ($userData["inviteban"] == 1) {
					$adminlogs->create(L::get("INVITEBAN_ADDED", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("INVITEBAN_ADDED_LOG", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				} else {
					$adminlogs->create(L::get("INVITEBAN_REMOVED", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("INVITEBAN_REMOVED_LOG", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				}
			}

			if ($user["forumban"] != $userData["forumban"]) {
				if ($userData["forumban"] == 1) {
					$adminlogs->create(L::get("FORUMBAN_ADDED", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("FORUM_BANNED_BY", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				} else {
					$adminlogs->create(L::get("FORUMBAN_REMOVED", [$user["id"], $user["username"], $user["username"]], Config::DEFAULT_LANGUAGE));
					$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("FORUM_UNBANNED_BY", [$this->getUsername()], Config::DEFAULT_LANGUAGE));
				}
			}

			if ($this->hashEmail($user["email"]) != $this->hashEmail($userData["email"])) {
				$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("EMAIL_CHANGE_LOG", [$this->hashEmail($user["email"]), $this->hashEmail($userData["email"]), $this->getUsername()], Config::DEFAULT_LANGUAGE));
				$this->addEmailLog($user["id"], $this->hashEmail($user["email"]));
			}

			if ($user["username"] != $userData["username"]) {
				$adminlogs->create(L::get("USERNAME_CHANGE_LOG", [$user["id"], $user["username"], $user["username"], $user["id"], $userData["username"], $userData["username"]], Config::DEFAULT_LANGUAGE));
				$userData["modcomment"] = $this->appendAdminComments($userData["modcomment"], L::get("USERNAME_CHANGE_ADMIN_LOG", [$user["username"], $userData["username"], $this->getUsername()], Config::DEFAULT_LANGUAGE));
			}
		}

		if ($this->getClass() >= User::CLASS_ADMIN) {
			$sth = $this->db->prepare("UPDATE users SET avatar = :avatar, gender = :gender, parkerad = :parkerad, alder = :alder, info = :info, mbitupp = :mbitupp, mbitner = :mbitner, isp = :isp, anonym = :anonym, anonymratio = :anonymratio, anonymicons = :anonymicons, acceptpms = :acceptpms, tvvy = :tvvy, https = :https, notifs = :notifs, avatars = :avatars, torrentsperpage = :torrentsperpage, topicsperpage = :topicsperpage, postsperpage = :postsperpage, passhash = :passhash, design = :design, css = :css, search_sort = :search_sort, doljuploader = :doljuploader, leechstart = :leechstart, invites = :invites, reqslots = :reqslots, forumban = :forumban, inviteban = :inviteban, uploadban = :uploadban, passkey = :passkey, warneduntil = :warneduntil, warned = :warned, username = :username, enabled = :enabled, bonuspoang = :bonuspoang, donor = :donor, downloaded = :downloaded, uploaded = :uploaded, title = :title, modcomment = :modcomment, email = :email, secret = :secret, class = :class, invited_by = :invited_by, section = :section, p2p = :p2p, language = :language WHERE id = :userId");

		} else {
			$sth = $this->db->prepare("UPDATE users SET avatar = :avatar, gender = :gender, parkerad = :parkerad, alder = :alder, info = :info, mbitupp = :mbitupp, mbitner = :mbitner, isp = :isp, anonym = :anonym, anonymratio = :anonymratio, anonymicons = :anonymicons, acceptpms = :acceptpms, tvvy = :tvvy, https = :https, notifs = :notifs, avatars = :avatars, torrentsperpage = :torrentsperpage, topicsperpage = :topicsperpage, postsperpage = :postsperpage, passhash = :passhash, design = :design, css = :css, search_sort = :search_sort, doljuploader = :doljuploader, section = :section, p2p = :p2p, language = :language WHERE id = :userId");
		}

		if ($this->getClass() >= User::CLASS_ADMIN) {
			$sth->bindParam(":leechstart",		$userData["leechstart"],		PDO::PARAM_STR);
			$sth->bindParam(":invites",			$userData["invites"],			PDO::PARAM_INT);
			$sth->bindParam(":reqslots",		$userData["reqslots"],			PDO::PARAM_INT);
			$sth->bindParam(":forumban",		$userData["forumban"],			PDO::PARAM_INT);
			$sth->bindParam(":inviteban",		$userData["inviteban"],			PDO::PARAM_INT);
			$sth->bindParam(":uploadban",		$userData["uploadban"],			PDO::PARAM_INT);
			$sth->bindParam(":passkey",			$userData["passkey"],			PDO::PARAM_STR);
			$sth->bindParam(":warned",			$userData["warned"],			PDO::PARAM_STR);
			$sth->bindParam(":warneduntil",		$userData["warned"],			PDO::PARAM_STR);
			$sth->bindParam(":username",		$userData["username"],			PDO::PARAM_STR);
			$sth->bindParam(":enabled",			$userData["enabled"],			PDO::PARAM_STR);
			$sth->bindParam(":bonuspoang",		$userData["bonuspoang"],		PDO::PARAM_INT);
			$sth->bindParam(":donor",			$userData["donor"],				PDO::PARAM_STR);
			$sth->bindParam(":downloaded",		$userData["downloaded"],		PDO::PARAM_INT);
			$sth->bindParam(":uploaded",		$userData["uploaded"],			PDO::PARAM_INT);
			$sth->bindParam(":title",			$userData["title"],				PDO::PARAM_STR);
			$sth->bindParam(":modcomment",		$userData["modcomment"],		PDO::PARAM_STR);
			$sth->bindValue(":email",			$this->hashEmail($userData["email"]),	PDO::PARAM_STR);
			$sth->bindParam(":secret",			$userData["secret"],			PDO::PARAM_STR);
			$sth->bindParam(":class",			$userData["class"],				PDO::PARAM_STR);
			$sth->bindParam(":invited_by",		$userData["invited_by"],		PDO::PARAM_INT);
		}

		$sth->bindParam(":avatar",			$userData["avatar"],			PDO::PARAM_STR);
		$sth->bindParam(":gender",			$userData["gender"],			PDO::PARAM_INT);
		$sth->bindParam(":parkerad",		$userData["parkerad"],			PDO::PARAM_INT);
		$sth->bindParam(":alder",			$userData["alder"],				PDO::PARAM_INT);
		$sth->bindParam(":info",			$userData["info"],				PDO::PARAM_STR);
		$sth->bindParam(":mbitupp",			$userData["mbitupp"],			PDO::PARAM_STR);
		$sth->bindParam(":mbitner",			$userData["mbitner"],			PDO::PARAM_STR);
		$sth->bindParam(":isp",				$userData["isp"],				PDO::PARAM_STR);
		$sth->bindParam(":anonym",			$userData["anonym"],			PDO::PARAM_STR);
		$sth->bindParam(":anonymratio", 	$userData["anonymratio"],		PDO::PARAM_STR);
		$sth->bindParam(":anonymicons",		$userData["anonymicons"],		PDO::PARAM_STR);
		$sth->bindParam(":acceptpms",		$userData["acceptpms"],			PDO::PARAM_STR);
		$sth->bindParam(":tvvy", 			$userData["tvvy"],				PDO::PARAM_INT);
		$sth->bindParam(":https", 			$userData["https"],				PDO::PARAM_INT);
		$sth->bindParam(":notifs", 			$userData["notifs"],			PDO::PARAM_STR);
		$sth->bindParam(":avatars", 		$userData["avatars"],			PDO::PARAM_STR);
		$sth->bindParam(":torrentsperpage", $userData["torrentsperpage"],	PDO::PARAM_INT);
		$sth->bindParam(":topicsperpage",	$userData["topicsperpage"],		PDO::PARAM_INT);
		$sth->bindParam(":postsperpage",	$userData["postsperpage"],		PDO::PARAM_INT);
		$sth->bindParam(":passhash",		$userData["passhash"],			PDO::PARAM_STR);
		$sth->bindParam(":design",			$userData["design"],			PDO::PARAM_INT);
		$sth->bindParam(":css",				$userData["css"],				PDO::PARAM_STR);
		$sth->bindParam(":search_sort",		$userData["search_sort"],		PDO::PARAM_STR);
		$sth->bindParam(":doljuploader",	$userData["doljuploader"],		PDO::PARAM_INT);
		$sth->bindParam(":section",			$userData["section"],			PDO::PARAM_STR);
		$sth->bindParam(":p2p",				$userData["p2p"],				PDO::PARAM_INT);
		$sth->bindParam(":language",		$userData["language"],			PDO::PARAM_STR);
		$sth->bindParam(":userId",			$userId,						PDO::PARAM_INT);
		$sth->execute();

		if ($changedPassword && $this->getId() == $userId) {
			$this->login($user["username"], $userData["password"]);
		}
	}

	private function addEmailLog($userId, $email) {
		$sth = $this->db->prepare("INSERT INTO emaillog(userid, datum, email) VALUES(?, NOW(), ?)");
		$sth->bindParam(1, $userId,		PDO::PARAM_INT);
		$sth->bindParam(2, $email,		PDO::PARAM_STR);
		$sth->execute();
	}

	private function appendAdminComments($modcomments, $text) {
		$text = date("Y-m-d") . " - " .$text .= "\n";
		return $text . $modcomments;
	}

	public function get($id, $forcedAllInfo = false) {
		$finalFields = array();
		$finalFields = array_merge($finalFields, self::getDefaultFields());

		if ($this->getId() == $id || $this->getClass() >= self::CLASS_ADMIN || $forcedAllInfo) {
			$finalFields = array_merge($finalFields, $this->getSelfFields());
		}

		if ($this->getClass() >= self::CLASS_ADMIN || $forcedAllInfo) {
			$finalFields = array_merge($finalFields, $this->getAdminFields());
		}

		$sth = $this->db->prepare('SELECT users.id AS uid, '.implode(',', $finalFields).' FROM users WHERE id = ?');
		$sth->bindParam(1, $id, PDO::PARAM_INT);
		$sth->execute();
		$arr = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$arr) {
			throw new Exception(L::get("USER_NOT_EXIST"), 404);
		}

		$arr["notifs"] = $arr["notifs"] ? explode(",", $arr["notifs"]) : [];

		if ($this->getId() != $arr["id"] && $this->getClass() < self::CLASS_ADMIN) {
			$arr["class"] = $this->calculateClass($arr["class"], $arr["doljuploader"]);
			$arr["doljuploader"] = null;
		}

		if ($arr["anonym"] == "yes" && $this->getClass() < self::CLASS_ADMIN && $this->getId() != $arr["id"]) {
			$arr["peersLeecher"] = null;
			$arr["peersSeeder"] = null;
		}

		if ($arr["anonymratio"] == "yes" && $this->getClass() < self::CLASS_ADMIN && $this->getId() != $arr["id"] && !$forcedAllInfo) {
			$arr["downloaded"] = null;
			$arr["downloaded_real"] = null;
			$arr["uploaded"] = null;
			$arr["uploadedTorrents"] = null;
		}

		if ($arr["anonymicons"] == "yes" && $this->getClass() < self::CLASS_ADMIN && $this->getId() != $arr["id"] && !$forcedAllInfo) {
			$arr["leechbonus"] = null;
			$arr["pokal"] = null;
		}

		if ($arr["invited_by"] && $this->getId() == $arr["id"] || $this->getClass() >= self::CLASS_ADMIN && !$forcedAllInfo) {
			try {
				$arr["invitedByUser"] = $this->get($arr["invited_by"]);
			} catch (Exception $e) {
				$arr["invitedByUser"] = null;
			}
		}

		return $arr;
	}

	public function getPeers($userId) {
		$sth = $this->db->prepare("SELECT anonym FROM users WHERE id = ?");
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->execute();
		$res = $sth->fetch();

		if (!$res) {
			throw new Exception(L::get("USER_NOT_EXIST"), 404);
		}

		if ($res[0] == "yes" && $this->getClass() < self::CLASS_ADMIN && $this->getId() != $userId) {
			return Array(array(), array());
		}

		$sth = $this->db->prepare('SELECT torrents.id, peers.torrent, peers.added, peers.uploaded, peers.downloaded, torrents.name,reqid,size,category,seeders,leechers,connectable,ip,port,agent,p2p,swesub,pack,3d, imdbinfo.genres, imdbinfo.photo, imdbinfo.rating, imdbinfo.imdbid AS imdbid2 FROM peers LEFT JOIN torrents ON peers.torrent = torrents.id LEFT JOIN imdbinfo ON torrents.imdbid = imdbinfo.id WHERE userid = ? AND seeder="yes" ORDER BY torrents.name ASC');
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->execute();

		$seeding = $sth->fetchAll(PDO::FETCH_ASSOC);

		$sth = $this->db->prepare('SELECT torrents.id, peers.torrent, peers.added, peers.uploaded, peers.downloaded, torrents.name,size,reqid,category,seeders,leechers,connectable,ip,port,agent,p2p,swesub,pack,3d, imdbinfo.genres, imdbinfo.photo, imdbinfo.rating, imdbinfo.imdbid AS imdbid2 FROM peers LEFT JOIN torrents ON peers.torrent = torrents.id LEFT JOIN imdbinfo ON torrents.imdbid = imdbinfo.id WHERE userid = ? AND seeder="no" ORDER BY torrents.name ASC');
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->execute();

		$leeching = $sth->fetchAll(PDO::FETCH_ASSOC);

		return Array($seeding, $leeching);
	}

	public function getBonusLog($userId, $limit) {
		if ($this->getId() != $userId && $this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$sth = $this->db->prepare('SELECT id, datum, msg FROM bonuslog WHERE userid = ? ORDER BY id DESC LIMIT ?');
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->bindParam(2, $limit, PDO::PARAM_INT);
		$sth->execute();

		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getIpLog($userId, $limit) {
		if ($this->getId() != $userId && $this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$sth = $this->db->prepare('SELECT id, ip, host, lastseen, uptime FROM iplog WHERE userid = ? ORDER BY lastseen DESC LIMIT ?');
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->bindParam(2, $limit, PDO::PARAM_INT);
		$sth->execute();

		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getUsers($search) {
		$searchText = $search . "%";
		$sth = $this->db->prepare('SELECT id, username FROM users WHERE username LIKE ? ORDER BY username ASC LIMIT 5');
		$sth->bindParam(1, $searchText, PDO::PARAM_STR, 10);
		$sth->execute();

		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getInvitees($userId) {
		if ($this->getId() != $userId && $this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$sth = $this->db->prepare('SELECT id, username, uploaded, downloaded, enabled, last_access, class FROM users WHERE invited_by = ?');
		$sth->bindParam(1, $userId, PDO::PARAM_INT);
		$sth->execute();

		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getNewMessages() {
		$sth = $this->db->prepare('SELECT COUNT(*) FROM messages WHERE receiver = ? AND unread = ? AND var = 0');
		$sth->execute(array($this->getId(), 'yes'));
		if ($arr = $sth->fetch()) {
			return $arr[0];
		}
	}

	public function getUnreadFlashNews() {
		$sth = $this->db->prepare('SELECT COUNT(*) FROM news WHERE announce = 1 AND id > ?');
		$sth->bindValue(1, $this->getLastReadNews(), PDO::PARAM_INT);
		$sth->execute();
		if ($arr = $sth->fetch()) {
			return $arr[0];
		}
	}

	public function usernameIsAvailable($username) {
		$sth = $this->db->prepare('SELECT 1 FROM users WHERE username = ?');
		$sth->execute(array($username));
		return count($sth->fetchAll()) === 0;
	}

	public function emailIsAvailable($email) {
		$sth = $this->db->prepare('SELECT 1 FROM users WHERE email = ?');
		$sth->execute(array($email));
		return count($sth->fetchAll()) === 0;
	}

	public function delete($id) {
		if ($this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$user = $this->get($id);

		foreach (explode(", ", "iplog, bonuslog, bookmarks, snatch, reqvotes, blocks, bevaka, cheatlog, invites, readposts, peers") as $x) {
			$this->db->query("DELETE FROM ".$x." WHERE userid = " . $id);
		}

		$this->db->query("DELETE FROM users WHERE id = " . $id);
		$this->db->query("DELETE FROM messages WHERE receiver = " . $id);
		$this->db->query("DELETE FROM friends WHERE friendid = " . $id . " OR userid = " . $id);

		$adminlogs = new AdminLogs($this->db, $this);
		$adminlogs->create(L::get("ACCOUNT_REMOVED_LOG", [$user["username"]], Config::DEFAULT_LANGUAGE));
	}

	public function loggaUt() {
		setcookie("uid", "", time(), "/" );
		setcookie("hash", "", time(), "/" );
	}

	private function setPrivateVars($arr) {
		$this->loggedIn = true;
		$this->id = (int) $arr["id"];
		$this->ip = $arr["ip"];
		$this->email = $arr["email"];
		$this->username = $arr["username"];
		$this->class = (int) $arr["class"];
		$this->indexList = $arr["indexlist"];
		$this->uplLastReadCommentId = $arr["uplLastReadCommentId"];
		$this->last_bevakabrowse = $arr["last_bevakabrowse"];
		$this->age = $arr["alder"];
		$this->bonus = $arr["bonuspoang"];
		$this->requestSlots = $arr["reqslots"];
		$this->invites = $arr["invites"];
		$this->https = $arr["https"];
		$this->passkey = $arr["passkey"];
		$this->lastAccess = strtotime($arr["last_access"]);
		$this->leechStart = $arr["leechstart"];
		$this->lastreadnews = $arr["lastreadnews"];
		$this->language = $arr["language"];
		$this->user = $arr;
	}

	public function setTypsetting($typsetting) {
		DB::query('UPDATE users SET typsetting = ' .$typsetting. ' WHERE id = ' . $this->id );
	}

	public function updateLastAccess() {
		$sth = $this->db->prepare("UPDATE users SET last_access = NOW(), ip = ?, muptime = muptime + 60 WHERE id= ?");
		$sth->execute(array($this->getBrowserIp(), $this->getId()));

		$sth = $this->db->prepare("UPDATE iplog SET lastseen = NOW(), uptime = uptime + 60 WHERE ip = ? AND userid = ?");
		$sth->execute(array($this->getBrowserIp(), $this->getId()));
	}

	public function logIp() {
		/* Ip has changed since last visit */
		if ($this->ip !== $this->getBrowserIp()) {

			/* See if this new IP has been used before on this account */
			$sth = $this->db->prepare('SELECT COUNT(*) FROM iplog WHERE ip = ? AND userid = ?');
			$sth->execute(array($this->getBrowserIp(), $this->getId()));
			$res = $sth->fetch();
			$count = $res[0];

			if ($count == 0) {
				$warningSignals = 0;

				/* Log the new IP */
				$host = gethostbyaddr($this->getBrowserIp());
				$sth = $this->db->prepare("INSERT INTO iplog(userid, ip, lastseen, host) VALUES (?, ?, NOW(), ?)");
				$sth->execute(array($this->getId(), $this->getBrowserIp(), $host));

				/* Check if domain has changed to a "strange" country-code eg. hacked or sold account */
				$currentHost = gethostbyaddr($this->getIp());
				if (substr($host, -2) != substr($currentHost, -2)) {
					$bannedCountryCodes = "za au ch fr ie ar mx hu tr it pl il jp ro nz sk fo sg cn ru uk rs de gr es vn pt hr";
					$bannedCountryCodesArray = explode(" ", $bannedCountryCodes);
					if (array_search(substr($currentHost, -2), $bannedCountryCodesArray) !== false) {
						$warningSignals++;
					}
				}

				/* Check if IP has been used to try to access a banned user account */
				$sth = $this->db->prepare("SELECT COUNT(*) FROM inlogg JOIN users ON inlogg.uid = users.id WHERE inlogg.ip = ? AND enabled = 'no'");
				$sth->execute(array($this->getBrowserIp()));
				$res = $sth->fetch();
				if ($res[0]) {
					$warningSignals++;
				}

				/* Check if IP has been used on a banned user account */
				$sth = $this->db->prepare("SELECT COUNT(*) FROM iplog JOIN users ON iplog.userid = users.id WHERE iplog.ip = ? AND enabled = 'no'");
				$sth->execute(array($this->getBrowserIp()));
				$res = $sth->fetch();
				if ($res[0]) {
					$warningSignals++;
				}

				/* Log to Staff if enough warning signals */
				if ($warningSignals > 0) {
					$sth = $this->db->prepare("INSERT INTO ipchanges(userid, datum, ip, hostname, level) VALUES(?, NOW(), ?, ?, ?)");
					$sth->bindValue(1, $this->getId(), PDO::PARAM_INT);
					$sth->bindValue(2, $this->getBrowserIp(), PDO::PARAM_INT);
					$sth->bindParam(3, $host, PDO::PARAM_INT);
					$sth->bindParam(4, $warningSignals, PDO::PARAM_INT);
					$sth->execute();
				}
			}
		}
	}

	public function isLoggedIn() {
		return $this->loggedIn;
	}

	public function getUser() {
		return $this->user;
	}

	public function getUsername() {
		return $this->username;
	}

	public function getId() {
		return $this->id;
	}

	public function getEmail() {
		return $this->email;
	}

	public function getIp() {
		return $this->ip;
	}

	public function getAge() {
		return $this->age;
	}

	public function getInvites() {
		return $this->invites;
	}

	public function getBonus() {
		return $this->bonus;
	}

	public function getRequestSlots() {
		return $this->requestSlots;
	}

	public function getBrowserIp() {
		if ($this->getClass() >= self::CLASS_DIRECTOR) {
			return "123.123.123.123";
		} else {
			return $_SERVER["REMOTE_ADDR"];
		}
	}

	public function getClass() {
		return $this->class;
	}

	public function getIndexList() {
		return $this->indexList;
	}

	public function getHttps() {
		return ($this->https == 1);
	}

	public function getPasskey() {
		return $this->passkey;
	}

	public function isForumBanned() {
		return ($this->user["forumban"] == 1);
	}

	public function isInviteBanned() {
		return ($this->user["inviteban"] == 1);
	}

	public function isUploadBanned() {
		return ($this->user["uploadban"] == 'yes');
	}

	public function getLanguage() {
		return $this->language;
	}

	public function getLastWatch() {
		return $this->last_bevakabrowse;
	}

	public function getLastReadNews() {
		return $this->lastreadnews;
	}

	public function getUplLastReadCommentId() {
		return $this->uplLastReadCommentId;
	}

	public function getLastAccess() {
		return $this->lastAccess;
	}

	public function getLeechStart() {
		return $this->leechStart;
	}

	private function hashPassword($password) {
		$options = [
			"cost" => 8
		];
		return password_hash($password . User::PASSWORD_SALT, PASSWORD_BCRYPT, $options);
	}

	private function hashCookie($passhash, $hashWithIp) {
		if ($hashWithIp == "true") {
			return md5($passhash . User::COOKIE_SALT . $_SERVER["REMOTE_ADDR"]);
		} else {
			return md5($passhash . User::COOKIE_SALT);
		}
	}

	public function getCustomIndex($id) {
		$sth = $this->db->prepare('SELECT * FROM customindex WHERE id = ?');
		$sth->bindParam(1, $id, PDO::PARAM_INT);
		$sth->execute();

		return $sth->fetch(PDO::FETCH_ASSOC);
	}

	public function getUsersLechbonusTop() {
		if ($this->getClass() < User::CLASS_MOVIE_STAR) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$res = $this->db->query('SELECT id, username, leechbonus, anonym, anonymicons, enabled, donor, coin, crown, warned, pokal FROM users ORDER BY leechbonus DESC LIMIT 200');
		$bonusTop = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row2 = $this->generateUserObject($row, false, true);
			$row2["nytt_seed"] = $row["nytt_seed"];
			$row2["leechbonus"] = $row["leechbonus"];
			array_push($bonusTop, $row2);
		}
		return $bonusTop;
	}

	public function getTopSeeders() {
		if ($this->getClass() < User::CLASS_MOVIE_STAR) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		$arr = array();
		$res = $this->db->query('SELECT id, username, nytt_seed, pokal, anonymratio, anonymicons, leechbonus, enabled, donor, coin, crown, warned FROM users WHERE enabled = "yes" ORDER BY nytt_seed DESC LIMIT 50');

		$newSeeds = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row2 = $this->generateUserObject($row, true, false);
			$row2["nytt_seed"] = $row["nytt_seed"];
			array_push($newSeeds, $row2);
		}

		$res = $this->db->query('SELECT id, username, arkiv_seed, pokal, anonym, anonymratio, anonymicons, leechbonus, enabled, donor, coin, crown, warned FROM users WHERE enabled = "yes" ORDER BY arkiv_seed DESC LIMIT 50');

		$archiveSeeds = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row2 = $this->generateUserObject($row, true, false);
			$row2["arkiv_seed"] = $row["arkiv_seed"];
			array_push($archiveSeeds, $row2);
		}

		return array("new" => $newSeeds, "archive" => $archiveSeeds);
	}

	public static function getDefaultFields() {
		return array(
			'users.id',
			'users.username',
			'users.enabled',
			'users.added',
			'users.acceptpms',
			'users.last_access',
			'users.avatar',
			'users.bonuspoang',
			'users.class',
			'users.coin',
			'users.crown',
			'users.gender',
			'users.donor',
			'users.downloaded',
			'users.downloaded_real',
			'users.uploaded',
			'users.leechbonus',
			'users.pokal',
			'users.skull',
			'users.hearts',
			'users.title',
			'users.warned',
			'users.alder',
			'users.info',
			'users.mbitupp',
			'users.mbitner',
			'users.isp',
			'users.doljuploader',
			'users.anonymratio',
			'users.anonymicons',
			'users.section',
			'users.p2p',
			'users.language'
			);
	}

	private function getAdminFields() {
		return array(
			'leechstart',
			'invites',
			'reqslots',
			'forumban',
			'inviteban',
			'uploadban',
			'passkey',
			'warned',
			'modcomment',
			'secret',
			'ip',
			'torrentip'
			);
	}

	private function getSelfFields() {
		return array(
			'tvvy',
			'https',
			'notifs',
			'parkerad',
			'avatars',
			'torrentsperpage',
			'topicsperpage',
			'postsperpage',
			'email',
			'(SELECT COUNT(*) FROM users WHERE invited_by = uid) AS invitees',
			'(SELECT COUNT(*) FROM comments WHERE user = uid) AS torrentComments',
			'(SELECT COUNT(*) FROM posts WHERE userid = uid) AS forumPosts',
			'(SELECT COUNT(*) FROM torrents WHERE reqid = 0 AND owner = uid) AS torrents',
			'(SELECT COUNT(*) FROM torrents WHERE reqid > 0 AND owner = uid) AS requests',
			'(SELECT COUNT(*) FROM peers WHERE seeder = "yes" AND userid = uid) AS peersSeeder',
			'(SELECT COUNT(*) FROM peers WHERE seeder = "no" AND userid = uid) AS peersLeecher',
			'anonym',
			'nytt_seed',
			'arkiv_seed',
			'design',
			'css',
			'invited_by',
			'search_sort'
			);
	}

	public function initTorrentComments() {
		if ($this->getUplLastReadCommentId() == 0) {
			$this->db->query('UPDATE users SET uplLastReadCommentId = 1 WHERE id = ' . $this->getId());
		}
	}

	public function updateLastReadTorrentComment($id, $lastReadId) {
		$this->db->query("UPDATE users SET uplLastReadCommentId = " . $lastReadId . " WHERE id = " . $id);
	}

	public function getAmountUnreadWatch() {
		$sth = $this->db->query("SELECT COUNT(*) FROM bevaka JOIN torrents on bevaka.imdbid = torrents.imdbid LEFT JOIN imdbinfo ON torrents.imdbid = imdbinfo.id WHERE (((torrents.category IN(4,5,6,7)) AND bevaka.swesub = 1 AND torrents.swesub = 1) OR ((torrents.category IN(4,5,6,7)) AND bevaka.swesub = 0) OR (torrents.category NOT IN(4,5,6,7))) AND FIND_IN_SET(torrents.category, bevaka.format) AND (category = 2 AND torrents.p2p = 1 OR category <> 2 AND torrents.p2p = 0) AND torrents.pack = 0 AND torrents.3d = 0 AND UNIX_TIMESTAMP(torrents.added) > " . $this->getLastWatch() ." AND bevaka.userid = " . $this->getId());
		$res = $sth->fetch();
		return $res[0];
	}

	public function updateLastTorrentViewAccess($page) {
		$possiblePages = array("last_browse", "last_reqbrowse", "last_tvbrowse", "last_seriebrowse", "last_ovrigtbrowse", "last_allbrowse", "last_bevakabrowse");

		if (!in_array($page, $possiblePages)) {
			return false;
		}

		$this->db->query("UPDATE users SET ".$page." = UNIX_TIMESTAMP(NOW()) WHERE id = ".$this->getId());
	}

	public function bonusLog($bonus, $reason, $userid) {
		$this->db->query("UPDATE users SET bonuspoang = bonuspoang + ".$bonus." WHERE id = " . $userid);

		$message = $reason.' [b]'.($bonus > 0 ?'+':'').$bonus.'p[/b]';
		$sth = $this->db->prepare('INSERT INTO bonuslog(userid, datum, msg, veckobonus) VALUES(?, NOW(), ?, ?)');
		$sth->bindParam(1,	$userid,				PDO::PARAM_INT);
		$sth->bindParam(2,	$message,				PDO::PARAM_STR);
		$sth->bindParam(3,	$bonus,					PDO::PARAM_INT);
		$sth->execute();
	}

	public function addIndexList($postdata) {
		$arr = explode(',', $this->getIndexList());
		if (!$arr[0]) {
			$arr = array();
		}

		if (count($arr) == 4 ) {
			throw new Exception(L::get("INDEX_LIST_LIMIT_REACHED"));
		}

		/* Check if a similiar list already exists in the database */
		$sth = $this->db->prepare("SELECT id FROM customindex WHERE tid = ? AND typ = ? AND format = ? AND sektion = ? AND sort = ? AND genre = ?");
		$sth->bindParam(1, $postdata["tid"],		PDO::PARAM_INT);
		$sth->bindParam(2, $postdata["typ"],		PDO::PARAM_INT);
		$sth->bindParam(3, $postdata["format"],		PDO::PARAM_INT);
		$sth->bindParam(4, $postdata["sektion"],	PDO::PARAM_INT);
		$sth->bindParam(5, $postdata["sort"],		PDO::PARAM_INT);
		$sth->bindParam(6, $postdata["genre"],		PDO::PARAM_STR);
		$sth->execute();
		$res = $sth->fetch();

		/* If already exists, use that ID-number, otherwise create new and fetch the new ID */
		if ($res) {
			$id = $res[0];
		} else {
			$sth = $this->db->prepare("INSERT INTO customindex(tid, typ, format, sektion, sort, genre) VALUES(?, ?, ?, ?, ?, ?)");
			$sth->bindParam(1, $postdata["tid"],		PDO::PARAM_INT);
			$sth->bindParam(2, $postdata["typ"],		PDO::PARAM_INT);
			$sth->bindParam(3, $postdata["format"],		PDO::PARAM_INT);
			$sth->bindParam(4, $postdata["sektion"],	PDO::PARAM_INT);
			$sth->bindParam(5, $postdata["sort"],		PDO::PARAM_INT);
			$sth->bindParam(6, $postdata["genre"],		PDO::PARAM_STR);
			$sth->execute();
			$id = $this->db->lastInsertId();
		}

		/* Check if the user already has an identical list */
		foreach($arr as $t) {
			if ($id == $t) {
				throw new Exception(L::get("INDEX_LIST_DUPLICATE"));
			}
		}

		$arr[] = $id;

		$indexlist = implode(",", $arr);

		$sth = $this->db->prepare("UPDATE users SET indexlist = ? WHERE id = ?");
		$sth->bindParam(1, $indexlist,		PDO::PARAM_STR);
		$sth->bindValue(2, $this->getId(),	PDO::PARAM_INT);
		$sth->execute();
	}

	public function removeIndexList($id) {
		$arr = explode(',', $this->getIndexList());
		$newArray = Array();
		for ($i = 0; $i < count($arr); $i++) {
			if ($arr[$i] != $id) {
				$newArray[] = $arr[$i];
			}
		}

		$indexlist = trim(implode(",", $newArray));
		$sth = $this->db->prepare("UPDATE users SET indexlist = ? WHERE id = ?");
		$sth->bindParam(1, $indexlist,		PDO::PARAM_STR);
		$sth->bindValue(2, $this->getId(),	PDO::PARAM_INT);
		$sth->execute();
	}

	public function moveIndexList($id, $direction) {
		$arr = explode(',', $this->getIndexList());

		if($direction == 0) { /* Move up */

		 	for ($i = 0; $i < count($arr); $i++) {
		 		if($arr[$i] == $id) {
		 			$tmp = $arr[$i];
		 			$arr[$i] = $arr[$i-1];
		 			$arr[$i-1] = $tmp;

		 			break;
		 		}
		 	}

		} else if ($direction == 1) { /* Move down */

			for ($i = 0; $i < count($arr); $i++) {
		 		if($arr[$i] == $id) {
		 			$tmp = $arr[$i];
		 			$arr[$i] = $arr[$i+1];
		 			$arr[$i+1] = $tmp;

		 			break;
		 		}
		 	}
		}

		$indexlist = implode(",", $arr);
		$sth = $this->db->prepare("UPDATE users SET indexlist = ? WHERE id = ?");
		$sth->bindParam(1, $indexlist,		PDO::PARAM_STR);
		$sth->bindValue(2, $this->getId(),	PDO::PARAM_INT);
		$sth->execute();
	}

	public function resetIndexList($category) {

		if ($category == Config::$categories["DVDR_CUSTOM"]["id"]) {
			$customlist = '1,141'; // 720p
		} else if ($category == Config::$categories["DVDR_TV"]["id"]) {
			$customlist = '11,163'; // 1080p
		} else {
			$customlist = '2,6'; // DVDR
		}

		$sth = $this->db->prepare("UPDATE users SET indexlist = ? WHERE id = ?");
		$sth->bindParam(1, $customlist,		PDO::PARAM_STR);
		$sth->bindValue(2, $this->getId(),	PDO::PARAM_INT);
		$sth->execute();
	}

	public function calculateClass($class, $customClass) {
		if ($class >= self::CLASS_UPLOADER && $class > $customClass) {
			return $customClass;
		}
		return $class;
	}

	public function deleteIpLog($id) {
		if ($this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}
		$sth = $this->db->prepare("DELETE FROM iplog WHERE id = ?");
		$sth->bindParam(1, $id, PDO::PARAM_INT);
		$sth->execute();
	}

	public function generateUserObject($row, $anonymousRatio = false, $anonymousTorrents = false) {
		$user = array();

		if ((($anonymousTorrents && $row["anonym"] == "yes") || ($anonymousRatio && $row["anonymratio"] == "yes")) && $this->getClass() < self::CLASS_ADMIN && $this->getId() != $row["id"]) {
			return $user;
		}

		$user["id"] = $row["id"];
		$user["username"] = $row["username"];
		if ($row["anonymicons"] == "yes" && $this->getClass() < self::CLASS_ADMIN && $this->getId() != $row["id"]) {
			$user["leechbonus"] = null;
			$user["pokal"] = 0;
		} else {
			$user["leechbonus"] = $row["leechbonus"];
			$user["pokal"] = $row["pokal"];
		}
		$user["enabled"] = $row["enabled"];
		$user["parkerad"] = $row["parkerad"];
		$user["donor"] = $row["donor"];
		$user["coin"] = $row["coin"];
		$user["crown"] = $row["crown"];
		$user["warned"] = $row["warned"];
		$user["title"] = $row["title"];
		$user["avatar"] = $row["avatar"];
		$user["class"] = $this->calculateClass($row["class"], $row["doljuploader"]);
		if (($row["anonymratio"] == "yes" && $anonymousRatio) || ($anonymousTorrents && $row["anonym"] == "yes")) {
			$user["anonymous"] = yes;
		}
		$user["language"] = $row["language"];

		return $user;
	}

	public function getUserTorrents($userId, $requests = 0) {
		if ($this->getId() != $userId && $this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}
		$sth = $this->db->query('SELECT imdbinfo.genres, imdbinfo.photo, imdbinfo.rating, imdbinfo.imdbid AS imdbid2, '.implode(Torrent::$torrentFieldsUser, ', ').' FROM torrents LEFT JOIN imdbinfo ON torrents.imdbid = imdbinfo.id WHERE '. ($requests == 1 ? 'torrents.reqid > 0 AND' : '') . ' torrents.owner = '.$userId.' ORDER BY torrents.name ASC');
		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	private function getCurrentGbSeed() {
		$sth = $this->db->query("SELECT torrents.size, peers.to_go FROM peers JOIN torrents ON peers.torrent = torrents.id WHERE userid = ".$this->getId()." GROUP BY userid, torrent");

		$seeded = 0;
		while ($row = $sth->fetch()) {
			$seeded += ($row["size"] - $row["to_go"]);
		}

		return round($seeded / 1073741824);
	}

	public function updateLastForumAccess() {
		$this->db->query("UPDATE users SET forum_access = NOW() WHERE id = " . $this->getId());
	}

	public function getForumOnline() {
		$sth = $this->db->query('SELECT id, username, class, doljuploader FROM users WHERE forum_access >= (now() - INTERVAL 5 MINUTE) ORDER BY users.username ASC');
		$result = array();
		while($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$r = array();
			$r["id"] = $row["id"];
			$r["username"] = $row["username"];
			$r["class"] = $this->calculateClass($row["class"], $row["doljuploader"]);
			array_push($result, $r);
		}
		return $result;
	}

	private function getPastDaysSeed() {
		$sth = $this->db->query("SELECT SUM(gbseed) AS seedsum FROM leechbonus WHERE userid = " . $this->getId());
		$sth = $sth->fetch();
		return round($sth[0]/72, 2);
	}

	public function getSnatchLog($userId) {
		if ($this->getClass() < User::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}
		$sth = $this->db->query('SELECT snatch.*, snatch.id AS snatchId, torrents.id, torrents.p2p, torrents.pack, torrents.3d, torrents.swesub, torrents.category, torrents.frileech, torrents.name, imdbinfo.genres, imdbinfo.photo, imdbinfo.rating, imdbinfo.imdbid AS imdbid2 FROM snatch LEFT JOIN torrents ON snatch.torrentid = torrents.id LEFT JOIN imdbinfo ON torrents.imdbid = imdbinfo.id  WHERE snatch.userid = '.$userId.' ORDER BY snatch.id DESC');

		$result = array();
		while($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$snatch = array();
			$snatch["id"] = $row["snatchId"];
			$snatch["ip"] = $row["ip"];
			$snatch["port"] = $row["port"];
			$snatch["uploaded"] = $row["uploaded"];
			$snatch["downloaded"] = $row["downloaded"];
			$snatch["agent"] = $row["agent"];
			$snatch["connectable"] = $row["connectable"];
			$snatch["finishedat"] = $row["klar"];
			$snatch["lastaction"] = $row["lastaction"];
			$snatch["timesStarted"] = $row["timesStarted"];
			$snatch["timesCompleted"] = $row["timesCompleted"];
			$snatch["timesStopped"] = $row["timesStopped"];
			$snatch["timesUpdated"] = $row["timesUpdated"];
			$snatch["seedtime"] = $row["seedtime"];
			$snatch["torrent"] = array(
				"id" => $row["id"],
				"name" => $row["name"],
				"category" => $row["category"],
				"p2p" => $row["p2p"],
				"pack" => $row["pack"],
				"3d" => $row["3d"],
				"swesub" => $row["swesub"],
				"frileech" => $row["frileech"],
				"genres" => $row["genres"],
				"photo" => $row["photo"],
				"imdbid2" => $row["imdbid2"],
				"rating" => $row["rating"]);
			array_push($result, $snatch);
		}
		return $result;
	}

	public function search($getdata) {
		if ($this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		if ($getdata["email"]) {
			$hashedEmail = $this->hashEmail($getdata["email"]);
		}

		$index = (int)$getdata["index"] ?: 0;
		$limit = (int)$getdata["limit"] ?: 25;

		$where = array();
		$finalWhere = "";

		if (strlen($getdata["username"]) > 1) {
			$where[] = "username LIKE '%".$getdata["username"]."%'";
		}
		if ($getdata["ip"]) {
			$where[] = "(ip LIKE '".$getdata["ip"]."%' OR torrentip LIKE '".$getdata["ip"]."%')";
		}
		if ($hashedEmail) {
			$where[] = "email = '".$hashedEmail."'";
		}

		if (count($where) > 0) {
			$finalWhere = " WHERE " . implode(" AND ", $where);
		}

		$sth = $this->db->query("SELECT COUNT(*) FROM users" .$finalWhere);
		$res = $sth->fetch();
		$totalCount = $res[0];

		$sth = $this->db->query("SELECT " . implode(", ", self::getDefaultFields()) . ", ip, email FROM users" . $finalWhere . " LIMIT $index, $limit");
		$users = $sth->fetchAll(PDO::FETCH_ASSOC);

		if (strlen($getdata["ip"]) > 2) {
			$sth = $this->db->query("SELECT iplog.ip, iplog.host, iplog.lastseen, iplog.uptime, users.username, users.id, users.enabled FROM iplog LEFT JOIN users ON iplog.userid = users.id WHERE iplog.ip = '".$getdata["ip"]."'");
			$iplog = $sth->fetchAll(PDO::FETCH_ASSOC);
		}
		if (strlen($getdata["ip"]) > 2 || strlen($getdata["username"]) > 1) {
			$loginAttempts = new LoginAttempts($this->db, $this);
			$loginAttempts = $loginAttempts->query(array("limit" => 99, "ip" => $getdata["ip"], "username" => $getdata["username"]));
		}
		if ($hashedEmail || $getdata["ip"]) {
			$recoveryLog = new RecoveryLog($this->db, $this);
			$recoveryLog = $recoveryLog->query(array("limit" => 99, "email" => $hashedEmail, "ip" => $getdata["ip"]));
		}
		if ($hashedEmail) {
			$sth = $this->db->query("SELECT emaillog.datum, emaillog.email, users.username, users.id, users.ip, users.enabled FROM emaillog LEFT JOIN users ON emaillog.userid = users.id WHERE emaillog.email = '".$hashedEmail."'");
			$emailLog = $sth->fetchAll(PDO::FETCH_ASSOC);
		}

		return array(array("users" => $users, "iplog" => $iplog, "loginAttempts" => $loginAttempts[0], "recoveryLog" => $recoveryLog[0], "emailLog" => $emailLog), $totalCount);
	}

	/* Hash email if not already hashed otherwise just return the already hashed email */
	public function hashEmail($email) {
		if (!User::HASHED_EMAILS) {
			return $email;
		}
		if (strpos($email, '@') > 0) {
			$options = [
				"cost" => 8,
				"salt" => User::EMAIL_SALT
			];
			return sha1(password_hash(strtolower($email), PASSWORD_BCRYPT, $options));
		}
		return $email;
	}

	public function testEmail($userId, $email) {
		if ($this->getId() !== $userId && $this->getClass() < self::CLASS_ADMIN) {
			throw new Exception(L::get("PERMISSION_DENIED"), 401);
		}

		if ($this->getId() != $userId) {
			$sth = $this->db->prepare('SELECT email from users WHERE id = ?');
			$sth->bindValue(1, $userId, PDO::PARAM_INT);
			$sth->execute();
			if ($arr = $sth->fetch()) {
				$userHashedMail = $arr[0];
			}
		} else {
			$userHashedMail = $this->getEmail();
		}

		if ($userHashedMail === $this->hashEmail($email)) {
			return true;
		} else {
			throw new Exception(L::get("EMAIL_NOT_MATCHING"), 404);
		}
	}
}
