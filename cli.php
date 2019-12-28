<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * For a given string, content areas with links including it
 *
 * @package    cli
 * @subpackage embedded
 * @copyright  2019 Michael de Raadt <michaelderaadt@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

ini_set('memory_limit','1024M');
define('MAX_FILE', 1048576000); // 1000*1024*1024

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/filelib.php');

// Turn on implicit flushing of output.
ob_implicit_flush(1);

$longparams = [
    'table'     => null,                          // Table containing embedded URLs for links/images.
    'field'     => null,                          // Field in table above containing HTML text.
    'match'     => '',                            // String in urls to match.
    'except'    => '',                            // String in matched URLs indicating an exception.
    'backupdir' => 'C:\\inetpub\\wwwroot\\bbbackup\\',  // Local dir to download files into.
    'webpath'   => '/bbbackup/',                  // Equivalent dir in web view.
    'cookie'    => true,                          // Path to cookie. If not provided, uses MoodleDir/cookie.txt.
    'log'       => 'log.csv',                     // CSV log file.
    'help'      => false,                         // Show CLI options.
];

$shortmappings = [
    't' => 'table',
    'f' => 'field',
    'm' => 'match',
    'e' => 'except',
    'b' => 'backupdir',
    'w' => 'webpath',
    'c' => 'cookie',
    'l' => 'log',
    'h' => 'help',
];


// Get CLI params and check they are recognised.
list($options, $unrecognized) = cli_get_params($longparams, $shortmappings);
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || !$options['table'] || !$options['field'] || !$options['match']) {
    echo
"Find embedded files from remote site, store them locally and update links.

Options:
-t, --table     Table containing embedded URLs for links/images.
-f, --field     Field in table above containing HTML text.
-m, --match     String to match in URLs (eg domain).
-e, --except    List of string exceptions within matched URLs.
-b, --backupdir (optional) Local dir to download files into.
-w, --webpath   (optional) Equivalent dir in web view.
-c, --cookie    (optional) Path to cookie. If not provided, uses MoodleDir/cookie.txt.
-l, --log       (optional) CSV log file to append to.
-h, --help      (optional) Print out this help

Example:
\$ php report\\embedded\\cli.php --table=page --field=content --match=blackboard.cgs.act.edu.au --except=jsp,execute

Tables with HTML fields to check:
    assign, intro = Assignment Description
    book, intro = Book Introduction
    book_chapters, content = Book Chapter
    course, summary = Course Summary
    folder, intro = Folder Introduction
    forum, intro = Forum Introduction
    label, intro = Label Content
    page, intro = Page Introduction
    page, content = Page Content
    question, questiontext = Quiz Questions
    quiz, intro = Quiz Introduction
    url, intro = URL Introduction
    wiki, intro = Wiki Introduction
    wiki_pages, cachedcontent = Wiki Pages
";
    die;
}

// Check if the table and field exist.
$table = $options['table'];
$field = $options['field'];
$dbmanager = $DB->get_manager();
if (!$dbmanager->table_exists($table)) {
    echo "Table $table does not exist.";
    die;
}
if (!$dbmanager->field_exists($table, $field)) {
    echo "Field $field does not exist in table $table.";
    die;
}

// Chech match text.
$matchtext = $options['match'];
if (!$dbmanager->field_exists($table, $field)) {
    echo "You must supply match text (eg domain).";
    die;
}
$exceptions = false;
if($options['except']) {
    $exceptions = explode(',', $options['except']);
}

// Check the cookie file.
if ($options['cookie'] === true && !file_exists($CFG->dataroot.'/curl_cookie.txt')) {
    echo "No cookie file at $CFG->dataroot/curl_cookie.txt";
    die;
}
if ($options['cookie'] !== true && !file_exists($options['cookie'])) {
    echo 'No cookie file at '.$options['cookie'];
    die;
}
$curl = new curl(['cookie' => $options['cookie']]);

$backuppath = $options['backupdir'];
$webpath = $options['webpath'];
$matchtypes = ['image'=>'src', 'file'=>'href'];

// Get records that contain matching
$from = '{' . $table . '}';
$params = ['match' => '%'.$matchtext.'%', 'module' => $table];
$likewhere = $DB->sql_like('t.'.$field, ':match', false);
$sql = "SELECT cm.id as cmid, t.id, t.name, t.$field as text
        FROM $from t, {modules} m, {course_modules} cm
        WHERE $likewhere
          AND m.name = :module
          AND cm.module = m.id
          AND cm.instance = t.id";
$results = $DB->get_records_sql($sql, $params);

// Capture results in this...
$moduleswithlinks = [];

// Process each resulting matched record.
foreach ($results as $cmid => $htmlobject) {
    $moduleswithlinks[$cmid] = [];
    $moduleswithlinks[$cmid]['id'] = $htmlobject->id;
    $moduleswithlinks[$cmid]['name'] = $htmlobject->name;
    $moduleswithlinks[$cmid]['replacements'] = [];

    echo $htmlobject->name.' ';

    // Download the embedded files.
    foreach ($matchtypes as $type => $attribute) {
        preg_match_all('/'.$attribute.'="([^\s"]+)"/', $htmlobject->text, $match);
        foreach ($match[1] as $index => $url) {
            if (!array_key_exists($url, $moduleswithlinks[$cmid]['replacements'])  &&
                strpos($url, $matchtext) !== false && !($exceptions && containsstring($url, $exceptions))) {
                $filename = savefile($url, $curl, $backuppath);
                if ($filename != false) {
                    $moduleswithlinks[$cmid]['replacements'][$url] = ['type'=>$type, 'path'=>$webpath.$filename];
                    echo '.';
                }
            }
        }
    }
    echo "\n";

    // Make replacements in text field.
    foreach ($moduleswithlinks[$cmid]['replacements'] as $old => $newdetails) {
        $htmlobject->text = str_replace($old, $newdetails['path'], $htmlobject->text);
    }
    $DB->set_field($table, $field, $htmlobject->text, ['id' => $htmlobject->id]);

    // Output results.
    $handle = fopen($options['log'], 'a+');
    foreach ($moduleswithlinks[$cmid]['replacements'] as $url => $newdetails) {
        fwrite($handle, "$cmid,$table,$field,".$moduleswithlinks[$cmid]['id'].",".$newdetails['type'].",".$url.",".$newdetails['path']."\n");
    }
    fclose($handle);
}

//----------------------------------------------------------------------------------------------------------------------------------
function mime2ext($mime){
  $all_mimes = '{"png":["image\/png","image\/x-png"],"bmp":["image\/bmp","image\/x-bmp","image\/x-bitmap","image\/x-xbitmap","image\/x-win-bitmap","image\/x-windows-bmp","image\/ms-bmp","image\/x-ms-bmp","application\/bmp","application\/x-bmp","application\/x-win-bitmap"],"gif":["image\/gif"],"jpg":["image\/jpeg","image\/pjpeg"],"xspf":["application\/xspf+xml"],"vlc":["application\/videolan"],"wmv":["video\/x-ms-wmv","video\/x-ms-asf"],"au":["audio\/x-au"],"ac3":["audio\/ac3"],"flac":["audio\/x-flac"],"ogg":["audio\/ogg","video\/ogg","application\/ogg"],"kmz":["application\/vnd.google-earth.kmz"],"kml":["application\/vnd.google-earth.kml+xml"],"rtx":["text\/richtext"],"rtf":["text\/rtf"],"jar":["application\/java-archive","application\/x-java-application","application\/x-jar"],"zip":["application\/x-zip","application\/zip","application\/x-zip-compressed","application\/s-compressed","multipart\/x-zip"],"7zip":["application\/x-compressed"],"xml":["application\/xml","text\/xml"],"svg":["image\/svg+xml"],"3g2":["video\/3gpp2"],"3gp":["video\/3gp","video\/3gpp"],"mp4":["video\/mp4"],"m4a":["audio\/x-m4a"],"f4v":["video\/x-f4v"],"flv":["video\/x-flv"],"webm":["video\/webm"],"aac":["audio\/x-acc"],"m4u":["application\/vnd.mpegurl"],"pdf":["application\/pdf","application\/octet-stream"],"pptx":["application\/vnd.openxmlformats-officedocument.presentationml.presentation"],"ppt":["application\/powerpoint","application\/vnd.ms-powerpoint","application\/vnd.ms-office","application\/msword"],"docx":["application\/vnd.openxmlformats-officedocument.wordprocessingml.document"],"xlsx":["application\/vnd.openxmlformats-officedocument.spreadsheetml.sheet","application\/vnd.ms-excel"],"xl":["application\/excel"],"xls":["application\/msexcel","application\/x-msexcel","application\/x-ms-excel","application\/x-excel","application\/x-dos_ms_excel","application\/xls","application\/x-xls"],"xsl":["text\/xsl"],"mpeg":["video\/mpeg"],"mov":["video\/quicktime"],"avi":["video\/x-msvideo","video\/msvideo","video\/avi","application\/x-troff-msvideo"],"movie":["video\/x-sgi-movie"],"log":["text\/x-log"],"txt":["text\/plain"],"css":["text\/css"],"html":["text\/html"],"wav":["audio\/x-wav","audio\/wave","audio\/wav"],"xhtml":["application\/xhtml+xml"],"tar":["application\/x-tar"],"tgz":["application\/x-gzip-compressed"],"psd":["application\/x-photoshop","image\/vnd.adobe.photoshop"],"exe":["application\/x-msdownload"],"js":["application\/x-javascript"],"mp3":["audio\/mpeg","audio\/mpg","audio\/mpeg3","audio\/mp3"],"rar":["application\/x-rar","application\/rar","application\/x-rar-compressed"],"gzip":["application\/x-gzip"],"hqx":["application\/mac-binhex40","application\/mac-binhex","application\/x-binhex40","application\/x-mac-binhex40"],"cpt":["application\/mac-compactpro"],"bin":["application\/macbinary","application\/mac-binary","application\/x-binary","application\/x-macbinary"],"oda":["application\/oda"],"ai":["application\/postscript"],"smil":["application\/smil"],"mif":["application\/vnd.mif"],"wbxml":["application\/wbxml"],"wmlc":["application\/wmlc"],"dcr":["application\/x-director"],"dvi":["application\/x-dvi"],"gtar":["application\/x-gtar"],"php":["application\/x-httpd-php","application\/php","application\/x-php","text\/php","text\/x-php","application\/x-httpd-php-source"],"swf":["application\/x-shockwave-flash"],"sit":["application\/x-stuffit"],"z":["application\/x-compress"],"mid":["audio\/midi"],"aif":["audio\/x-aiff","audio\/aiff"],"ram":["audio\/x-pn-realaudio"],"rpm":["audio\/x-pn-realaudio-plugin"],"ra":["audio\/x-realaudio"],"rv":["video\/vnd.rn-realvideo"],"jp2":["image\/jp2","video\/mj2","image\/jpx","image\/jpm"],"tiff":["image\/tiff"],"eml":["message\/rfc822"],"pem":["application\/x-x509-user-cert","application\/x-pem-file"],"p10":["application\/x-pkcs10","application\/pkcs10"],"p12":["application\/x-pkcs12"],"p7a":["application\/x-pkcs7-signature"],"p7c":["application\/pkcs7-mime","application\/x-pkcs7-mime"],"p7r":["application\/x-pkcs7-certreqresp"],"p7s":["application\/pkcs7-signature"],"crt":["application\/x-x509-ca-cert","application\/pkix-cert"],"crl":["application\/pkix-crl","application\/pkcs-crl"],"pgp":["application\/pgp"],"gpg":["application\/gpg-keys"],"rsa":["application\/x-pkcs7"],"ics":["text\/calendar"],"zsh":["text\/x-scriptzsh"],"cdr":["application\/cdr","application\/coreldraw","application\/x-cdr","application\/x-coreldraw","image\/cdr","image\/x-cdr","zz-application\/zz-winassoc-cdr"],"wma":["audio\/x-ms-wma"],"vcf":["text\/x-vcard"],"srt":["text\/srt"],"vtt":["text\/vtt"],"ico":["image\/x-icon","image\/x-ico","image\/vnd.microsoft.icon"],"csv":["text\/x-comma-separated-values","text\/comma-separated-values","application\/vnd.msexcel"],"json":["application\/json","text\/json"]}';
  $all_mimes = json_decode($all_mimes,true);
  foreach ($all_mimes as $key => $value) {
    if(array_search($mime,$value) !== false) return $key;
  }
  return false;
}

function savefile($url, $curl, $storepath) {
    $lastslash = strrpos($url, '/');
    $filename = substr($url, $lastslash+1);
    $lastdot = strpos($filename, '.');

    $headers = $curl->head($url);
    if (!$headers || $curl->response['Content-Length'] == 0 || $curl->response['Content-Length'] > MAX_FILE) {
        return false;
    }

    $curl->resetopt();

    if (!$lastdot) {
        $mimetype = $curl->response['Content-Type'];
        $extension = mime2ext($mimetype);
        $filename = $filename.'.'.$extension;
    }

    if (!file_exists($storepath.$filename)) {
        $file = $curl->get($url, [], ['CURLOPT_TIMEOUT'=>300]);
        file_safe_save_content($file, $storepath.$filename);
    }
    return $filename;
}

function containsstring($haystack, $needles) {
    foreach ($needles as $needle) {
        if(strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}