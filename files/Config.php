<?php

class Config {

	const NAME = "HomeLANTracker";
	const SITE_NAME = "tracker.homelan.local";
	const SITE_URL = "https://tracker.homelan.local";
	const SITE_MAIL = "no-reply@tracker.homelan.local";

	const SUGGESTION_FORUM_ID = 4;
	const POLLS_FORUM_ID = 3;
	const NEWS_FORUM_ID = 1;

	const DEFAULT_LANGUAGE = "en";
	public static $languages = ["en", "sv"];

	const TRACKER_URL = "http://tracker.homelan.local:1337";
    const TRACKER_URL_SSL = "https://tracker.homelan.local:1338";

	public static $userClasses = array(
		0 => "Extra",
		1 => "Actor",
		2 => "Movie star",
		3 => "Director",
		4 => "Producer",
		6 => "Uploader",
		7 => "VIP",
		8 => "Staff");

	public static $categories = array(
		"DVDR_PAL" => array("id" => 1, "name" => "DVDR PAL"),
		"DVDR_CUSTOM" => array("id" => 2, "name" => "DVDR CUSTOM"),
		"DVDR_TV" => array("id" => 3, "name" => "DVDR TV"),
		"MOVIE_720P" => array("id" => 4, "name" => "720p Movie"),
		"MOVIE_1080P" => array("id" => 5, "name" => "1080p Movie"),
		"TV_720P" => array("id" => 6, "name" => "720p TV"),
		"TV_1080P" => array("id" => 7, "name" => "1080p TV"),
		"TV_AU" => array("id" => 8, "name" => "Australian TV"),
		"AUDIOBOOKS" => array("id" => 9, "name" => "Audiobook"),
		"EBOOKS" => array("id" => 10, "name" => "E-book"),
		"EPAPERS" => array("id" => 11, "name" => "E-paper"),
		"MUSIC" => array("id" => 12, "name" => "Music"),
		"BLURAY" => array("id" => 13, "name" => "Full BluRay"),
		"SUBPACK" => array("id" => 14, "name" => "Subpack"),
		"MOVIE_4K" => array("id" => 15, "name" => "4K Movie"),
    "MUSIC_VIDEO" => array("id" => 16, "name" => "Music Video"),
	);

}
