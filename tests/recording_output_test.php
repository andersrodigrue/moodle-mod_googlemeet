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

namespace mod_googlemeet;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Tests for safe recording presentation.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 Anderson Rodrigues
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class recording_output_test extends \advanced_testcase {

    /**
     * Historical rows are revalidated before their links reach a renderer.
     */
    public function test_recording_list_fails_closed_for_unsafe_playback_links(): void {
        $this->resetAfterTest();
        $meeting = $this->create_meeting();
        $this->create_recording(
            $meeting->id,
            'DriveFile_12345',
            'https://drive.google.com/file/d/DriveFile_12345/view?usp=drivesdk',
            20
        );
        $this->create_recording(
            $meeting->id,
            'UnsafeFile_12345',
            'javascript:alert(1)',
            10
        );

        $recordings = googlemeet_list_recordings(['googlemeetid' => $meeting->id]);

        $this->assertCount(2, $recordings);
        $this->assertTrue($recordings[0]->hasplayback);
        $this->assertSame(
            'https://drive.google.com/file/d/DriveFile_12345/view?usp=drivesdk',
            $recordings[0]->webviewlink
        );
        $this->assertFalse($recordings[1]->hasplayback);
        $this->assertNull($recordings[1]->webviewlink);
    }

    /**
     * The web template uses semantic controls and contains no inline script.
     */
    public function test_recording_template_has_safe_links_and_amd_action_hooks(): void {
        global $OUTPUT;

        $this->resetAfterTest();
        $this->setAdminUser();
        $recording = (object) [
            'id' => 7,
            'name' => '<script>alert(1)</script>',
            'createdtimeformatted' => '30 July 2026',
            'duration' => '10:00',
            'webviewlink' => 'https://drive.google.com/file/d/DriveFile_12345/view',
            'hasplayback' => true,
            'visible' => true,
        ];

        $html = $OUTPUT->render_from_template('mod_googlemeet/recordingstable', [
            'recordings' => [$recording],
            'hasrecordings' => true,
            'coursemoduleid' => 42,
            'canedit' => true,
            'canremove' => true,
        ]);

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('data-action="edit-name"', $html);
        $this->assertStringContainsString('data-action="set-visibility"', $html);
        $this->assertStringContainsString('data-action="delete-recordings"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    /**
     * Participants receive no mutation controls.
     */
    public function test_recording_template_hides_mutation_controls_without_capabilities(): void {
        global $OUTPUT;

        $this->resetAfterTest();
        $this->setAdminUser();
        $html = $OUTPUT->render_from_template('mod_googlemeet/recordingstable', [
            'recordings' => [],
            'hasrecordings' => false,
            'coursemoduleid' => 42,
            'canedit' => false,
            'canremove' => false,
        ]);

        $this->assertStringContainsString(
            get_string('thereisnorecordingtoshow', 'mod_googlemeet'),
            $html
        );
        $this->assertStringNotContainsString('data-action=', $html);
    }

    /**
     * Creates a minimal activity instance.
     *
     * @return \stdClass Activity record.
     */
    private function create_meeting(): \stdClass {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        return $this->getDataGenerator()
            ->get_plugin_generator('mod_googlemeet')
            ->create_instance(['course' => $course->id]);
    }

    /**
     * Creates one historical recording row.
     *
     * @param int $googlemeetid Activity ID.
     * @param string $recordingid Provider file ID.
     * @param string $webviewlink Stored playback link.
     * @param int $createdtime Creation time used for deterministic ordering.
     */
    private function create_recording(
        int $googlemeetid,
        string $recordingid,
        string $webviewlink,
        int $createdtime
    ): void {
        global $DB;

        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $googlemeetid,
            'recordingid' => $recordingid,
            'name' => 'Recording',
            'createdtime' => $createdtime,
            'duration' => '10:00',
            'webviewlink' => $webviewlink,
            'visible' => 1,
            'timemodified' => $createdtime,
        ]);
    }
}
