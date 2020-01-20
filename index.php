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
 * @package    report
 * @subpackage embedded
 * @copyright  2019 Michael de Raadt <michaelderaadt@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/filelib.php');

// Print the header & check permissions.
admin_externalpage_setup('reportembedded', '', null, '', array('pagelayout'=>'report'));
echo $OUTPUT->header();

// List of possible areas with content.
$selectoptions = [
    'assign-intro' => 'Assignment Description',
    'book-intro' => 'Book Introduction',
    'book_chapters-content' => 'Book Chapter',
    'course-summary' => 'Course Summary',
    'folder-intro' => 'Folder Introduction',
    'forum-intro' => 'Forum Introduction',
    'label-intro' => 'Label Content',
    'page-intro' => 'Page Introduction',
    'page-content' => 'Page Content',
    'question-questiontext' => 'Quiz Questions',
    'quiz-intro' => 'Quiz Introduction',
    'url-intro' => 'URL Introduction',
    'wiki-intro' => 'Wiki Introduction',
    'wiki_pages-cachedcontent' => 'Wiki Pages',
];

// TODO: HTML block.

// Get form data, if set.
$requestedtable = optional_param('table', '', PARAM_TEXT);
$matchtext = optional_param('match', '', PARAM_TEXT);
$excludetext = optional_param('exclude', '', PARAM_TEXT);

// Print the settings form.
echo $OUTPUT->box_start('generalbox boxwidthwide boxaligncenter centerpara');
echo '<form method="post" action="." id="settingsform"><div>';
echo '<label for="table"> ' . get_string('table', 'report_embedded') . '</label> ';
echo html_writer::select($selectoptions, 'table', $requestedtable);
echo ' ';
echo '<label for="match"> ' . get_string('match', 'report_embedded') . '</label> ';
echo '<input type="text" name="match" class="form-control" style="display:inline;width:200px;" value="' . $matchtext . '" />';
echo ' ';
echo '<label for="exclude"> ' . get_string('exclude', 'report_embedded') . '</label> ';
echo '<input type="text" name="exclude" class="form-control" style="display:inline;width:200px;" value="' . $excludetext . '" />';
echo ' ';
echo '<input type="submit" class="btn btn-secondary" id="settingssubmit" value="' .
     get_string('getreport', 'report_embedded') . '" />';
echo '</div></form>';
echo $OUTPUT->box_end();

// If we have a table to work on, start processing.
if ($requestedtable) {

    $divider = strpos($requestedtable, '-');
    $table = substr($requestedtable, 0, $divider);
    $field = substr($requestedtable, $divider+1);
    $matchtypes = ['image'=>'src', 'file'=>'href'];
    $exceptions = false;
    if($excludetext) {
        $exceptions = explode(',', $excludetext);
    }

    $from = '{' . $table . '}';
    $params = ['match' => '%'.$matchtext.'%', 'module' => $table];
    $likewhere = $DB->sql_like('t.'.$field, ':match', false);
    $name = 't.name';
    $nolink = false;
    switch ($table) {
        case 'book_chapters':
            $sql = "SELECT t.id, t.title as name, t.$field as text
                    FROM $from t
                    WHERE $likewhere";
            $nolink = true;
            break;
        case 'course':
            $sql = "SELECT t.id, t.shortname as name, t.$field as text
                    FROM $from t
                    WHERE $likewhere";
            $nolink = true;
            break;
        case 'question';
            $sql = "SELECT t.id, t.name, t.$field as text
                    FROM $from t
                    WHERE $likewhere";
            $nolink = true;
            break;
        case 'wiki_pages':
            $sql = "SELECT t.id, t.title as name, t.$field as text
                    FROM $from t
                    WHERE $likewhere";
            $nolink = true;
            break;
        default:
            $sql = "SELECT cm.id as cmid, t.id, $name, t.$field as text
                    FROM $from t, {modules} m, {course_modules} cm
                    WHERE $likewhere
                      AND m.name = :module
                      AND cm.module = m.id
                      AND cm.instance = t.id";
    }

    $results = $DB->get_records_sql($sql, $params);

    foreach ($results as $cmid => $htmlobject) {
        $moduleswithlinks[$cmid] = [];
        $moduleswithlinks[$cmid]['name'] = $htmlobject->name;
        $replacements = [];

        echo '<p>';
        if (!$nolink) {
            echo '<a href="'.$CFG->wwwroot.'/mod/'.$table.'/view.php?id='.$cmid.'">';
        }
        echo $htmlobject->name;
        if (!$nolink) {
            echo '</a>';
        }
        echo '</p>';
        echo '<ul>';

        foreach ($matchtypes as $type => $attribute) {
            preg_match_all('/'.$attribute.'="([^\s"]+)"/', $htmlobject->text, $match);
            foreach ($match[1] as $index => $url) {
                if (strpos($url, $matchtext) !== false && !($exceptions && containsstring($url, $exceptions))) {
                    echo "<li>$type: <a href=\"$url\">$url</a></li>";
                }
            }
        }

        echo '</ul>';
    }
}

// Footer.
echo $OUTPUT->footer();

//----------------------------------------------------------------------------------------------------------------------------------
function containsstring($haystack, $needles) {
    foreach ($needles as $needle) {
        if(strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}