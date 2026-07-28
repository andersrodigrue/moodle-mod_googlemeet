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
 * Plugin strings are defined here.
 *
 * @package     mod_googlemeet
 * @category    string
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['at'] = 'at';
$string['issuerid'] = 'OAuth service';
$string['issuerid_desc'] = '<a href="https://github.com/ronefel/moodle-mod_googlemeet/wiki/How-to-create-Client-ID-and-Client-Secret" target="_blank">How to set up an OAuth Service</a>';
$string['calendareventname'] = '{$a} is scheduled for';
$string['checkweekdays'] = 'Select the days of the week that fall within the selected date range.';
$string['date'] = 'Date';
$string['duration'] = 'Duration';
$string['earlierto'] = 'The event date cannot be earlier than the course start date ({$a}).';
$string['emailcontent'] = 'Email content';
$string['emailcontent_default'] = '<p>Hi %userfirstname%,</p>
<p>This reminder is to remind you that there will be a Google meet event in %coursename%</p>
<p><b>%googlemeetname%</b></p>
<p>When: %eventdate% %duration% %timezone%</p>
<p>Access link: %url%</p>';
$string['emailcontent_help'] = 'When a notification is sent to a student, it takes the email content from this field. The following wildcards can be used:
<ul>
<li>%userfirstname%</li>
<li>%userlastname%</li>
<li>%coursename%</li>
<li>%googlemeetname%</li>
<li>%eventdate%</li>
<li>%duration%</li>
<li>%timezone%</li>
<li>%url%</li>
<li>%cmid%</li>
</ul>';
$string['entertheroom'] = 'Enter the room';
$string['eventdate'] = 'Event date';
$string['eventdetails'] = 'Event details';
$string['from'] = 'from';
$string['googlemeet:addinstance'] = 'Add a new Google Meet™ for Moodle';
$string['googlemeet:editrecording'] = 'Edit recordings';
$string['googlemeet:managemeeting'] = 'Manage the Google Calendar meeting';
$string['googlemeet:removerecording'] = 'Remove recordings';
$string['googlemeet:syncgoogledrive'] = 'Sync with Google Drive';
$string['googlemeet:view'] = 'View Google Meet™ for Moodle content';
$string['hide'] = 'Hide';
$string['invalidactivitycontext'] = 'The requested item does not belong to this Google Meet activity.';
$string['invalideventenddate'] = 'This date can not be earlier than the "Event date"';
$string['invalideventendtime'] = 'The end time must be greater than start time';
$string['invalidissuerid'] = 'The OAuth service selected in the "Google Meet™ for Moodle" settings is not supported by Google';
$string['invalidintegrationmode'] = 'Select a supported meeting integration mode.';
$string['invalidsyncaction'] = 'This synchronization action is not available for the meeting in its current state.';
$string['invalidstoredurl'] = 'Cannot display this resource, Google Meet URL is invalid.';
$string['integration'] = 'Meeting integration';
$string['integrationlegacyupgrade'] = 'This activity uses the legacy Google integration. Saving it requires choosing '
    . 'either a managed Calendar meeting or an existing manual Meet link.';
$string['integrationmode'] = 'Meeting creation';
$string['integrationmode_help'] = 'Managed mode creates and reconciles the Google Calendar event asynchronously using '
    . 'your own authorization. Manual mode stores an existing Google Meet link without managing a Calendar event.';
$string['integrationmodemanaged'] = 'Create and manage with Google Calendar';
$string['integrationmodemanual'] = 'Use an existing Google Meet link';
$string['jstableinfo'] = 'Showing {start} to {end} of {rows} recordings';
$string['jstableinfofiltered'] = 'Showing {start} to {end} of {rows} recordings (filtered from {rowsTotal} recordings)';
$string['jstableloading'] = 'Loading...';
$string['jstablenorows'] = 'No recording found';
$string['jstableperpage'] = '{select} recordings per page';
$string['jstablesearch'] = 'Search...';
$string['lastsync'] = 'Last sync:';
$string['loading'] = 'Loading';
$string['logintoaccount'] = 'Log in to your Google account';
$string['logintoyourgoogleaccount'] = 'Log in to your Google account so that the Google Meet URL can be automatically created';
$string['loggedinaccount'] = 'Connected Google account';
$string['logout'] = 'Logout';
$string['manage'] = 'Manage';
$string['managedmodecannotchange'] = 'A managed meeting cannot be converted to a manual link while its remote Calendar '
    . 'lifecycle is active.';
$string['managedoauth'] = 'Google Calendar authorization';
$string['managedoauthclose'] = 'You can close this window and return to the activity form.';
$string['managedoauthconnect'] = 'Connect Google Calendar';
$string['managedoauthconnected'] = 'Google Calendar is connected for this teacher.';
$string['managedoauthfailed'] = 'Google Calendar authorization could not be completed.';
$string['managedoauthrequired'] = 'Connect your Google account before saving a managed meeting.';
$string['managedoauthunavailable'] = 'A configured Google OAuth service is required for managed meetings.';
$string['managedowneronly'] = 'Only the teacher who owns this managed meeting can update its Google Calendar integration.';
$string['managedroomurldesc'] = 'Managed meetings receive their Meet link after background synchronization. Enter a link '
    . 'only when using manual mode.';
$string['meetinglinknotready'] = 'The Google Meet link is not available yet. The activity will update after synchronization.';
$string['messageprovider:notification'] = 'Google Meet event start reminder';
$string['minutesbefore'] = 'Minutes before';
$string['minutesbefore_help'] = 'Number of minutes before the start of the event when the notification should be send.';
$string['modulename'] = 'Google Meet™ for Moodle';
$string['modulename_help'] = 'The Google Meet™ module for Moodle allows the teacher to create a Google Meet room as a course resource and after the meetings make available to the students the recordings, saved in Google Drive.
<p>©2018 Google LLC All rights reserved.<br/>
Google Meet and the Google Meet logo are registered trademarks of Google LLC.</p>';
$string['modulenameplural'] = 'Google Meet™ for Moodle instances';
$string['multieventdateexpanded'] = 'Recurrence of the event date expanded';
$string['multieventdateexpanded_desc'] = 'Show the "Recurrence of the event date" settings as expanded by default when creating new Room.';
$string['name'] = 'Name';
$string['never'] = 'Never';
$string['notification'] = 'Notification';
$string['notificationexpanded'] = 'Notification expanded';
$string['notify'] = 'Send notification to the student';
$string['notify_help'] = 'If checked, a notification will be sent to the student about the start date of the event.';
$string['notifycationexpanded_desc'] = 'Show the "Notification" settings as expanded by default when creating new Room.';
$string['notifytask'] = 'Google Meet™ for Moodle notification task';
$string['or'] = 'or';
$string['overviewjoinmeeting'] = 'Join meeting';
$string['overviewmeetingtime'] = 'Meeting time';
$string['overviewsyncstatus'] = 'Meeting status';
$string['play'] = 'Play';
$string['pluginadministration'] = 'Google Meet™ for Moodle administration';
$string['pluginname'] = 'Google Meet™ for Moodle';
$string['privacy:metadata:googlemeet_notify_done'] = 'Records notifications sent to users about the start of events. This data is temporary and is deleted after the event start date.';
$string['privacy:metadata:googlemeet_notify_done:eventid'] = 'The event ID';
$string['privacy:metadata:googlemeet_notify_done:userid'] = 'The user ID';
$string['privacy:metadata:googlemeet_notify_done:timesent'] = 'The timestamp indicating when the user received a notification';
$string['recording'] = 'Recording';
$string['recordings'] = 'Recordings';
$string['recordingswiththename'] = 'Recordings with the name:';
$string['reconcilependingresult'] = 'Calendar reconciliation found {$a->found} meeting(s) and queued {$a->queued}.';
$string['reconcilependingtask'] = 'Reconcile pending Google Meet conferences';
$string['recurrenceeventdate'] = 'Recurrence of the event date';
$string['recurrenceeventdate_help'] = 'This function makes it possible to create multiple recurrences from the event date.
<br>* <strong>Repeat on</strong>: Select the days of the week that your class will meet (for example, Monday / Wednesday / Friday).
<br>* <strong>Repeat every</strong>: This allows for a frequency setting. If your class will meet every week, select 1; will meet every two weeks, select 2; every 3 weeks, select 3, etc.
<br>* <strong>Repeat until</strong>: Select the last day of the meeting (the last day you want to take the recurring date of the event).';
$string['repeatasfollows'] = 'Repeat the event date above as follows';
$string['repeatevery'] = 'Repeat every';
$string['repeaton'] = 'Repeat on';
$string['repeatuntil'] = 'Repeat until';
$string['roomcreator'] = 'Organizer:';
$string['roomname'] = 'Room name';
$string['roomurl'] = 'Room url';
$string['roomurl_desc'] = 'The room URL will be automatically generated.';
$string['roomurlexpanded'] = 'Room url expanded';
$string['roomurlexpanded_desc'] = 'Show the "Room url" settings as expanded by default when creating new Room.';
$string['sessionexpired'] = 'Your Google account session expired in the middle of the process, please login again.';
$string['show'] = 'Show';
$string['strftimedm'] = '%a. %d %b.';
$string['strftimedmy'] = '%a. %d %b. %Y';
$string['strftimedmyhm'] = '%a. %d %b. %Y %H:%M';
$string['strftimehm'] = '%H:%M';
$string['syncactivitymissing'] = 'Google Meet activity {$a} no longer exists; synchronization was skipped.';
$string['syncactionqueued'] = 'The meeting synchronization was queued.';
$string['syncadapterunavailable'] = 'The managed Google Calendar synchronization adapter is not available yet.';
$string['synccalendarcancelapifailed'] = 'Google Calendar permanently rejected the cancellation request.';
$string['synccalendarapifailed'] = 'Google Calendar permanently rejected the synchronization request.';
$string['synccalendarconfigurationinvalid'] = 'The managed Google Calendar configuration is invalid.';
$string['synccalendarresponseinvalid'] = 'Google Calendar returned an incomplete or inconsistent event.';
$string['syncconferencecreationfailed'] = 'Google Calendar could not create the Google Meet conference.';
$string['synccancel'] = 'Cancel meeting';
$string['synccancelqueued'] = 'The meeting cancellation was queued.';
$string['syncinvalidintegrationmode'] = 'The stored Google Meet integration mode is invalid.';
$string['synclegacydisconnected'] = 'Google Meet activity {$a} requires Google reconnection.';
$string['synclocktimeout'] = 'Could not acquire the synchronization lock for Google Meet activity {$a}.';
$string['syncmanagedapifailed'] = 'Google Meet activity {$a} was rejected by Google Calendar.';
$string['syncmanagedcancelled'] = 'Google Meet activity {$a} was cancelled in Google Calendar.';
$string['syncmanagedconfigurationfailed'] = 'Google Meet activity {$a} has invalid managed Calendar configuration.';
$string['syncmanageddeferred'] = 'Google Meet activity {$a} is waiting for the managed Google Calendar adapter.';
$string['syncmanageddisconnected'] = 'Google Meet activity {$a} requires fresh Google Calendar authorization.';
$string['syncmanagedfailed'] = 'Google Meet activity {$a} received a failed conference creation result.';
$string['syncmanagedpending'] = 'Google Meet activity {$a} is waiting for Google to create the conference.';
$string['syncmanagedready'] = 'Google Meet activity {$a} is synchronized and ready.';
$string['syncmanagedresponsefailed'] = 'Google Meet activity {$a} received an invalid Calendar response.';
$string['syncmanualready'] = 'Google Meet activity {$a} uses a manual link and is ready.';
$string['syncoauthrequired'] = 'The meeting owner must authorize Google Calendar again before synchronization can continue.';
$string['syncreconnect'] = 'Reconnect Google Calendar';
$string['syncreconnectrequired'] = 'The meeting owner must reconnect Google before synchronization can continue.';
$string['syncrestoredreconnectrequired'] = 'This restored managed meeting must be claimed and reconnected before synchronization.';
$string['syncretry'] = 'Try synchronization again';
$string['syncstateskipped'] = 'Google Meet activity {$a->id} is in state {$a->state}; synchronization was skipped.';
$string['syncstatuscancelled'] = 'Cancelled';
$string['syncstatuscancelled_desc'] = 'The managed Calendar event has been cancelled.';
$string['syncstatuscancelling'] = 'Cancelling';
$string['syncstatuscancelling_desc'] = 'Moodle is waiting for remote cancellation to complete.';
$string['syncstatusdisconnected'] = 'Authorization required';
$string['syncstatusdisconnected_desc'] = 'The meeting owner must reconnect Google Calendar before synchronization can continue.';
$string['syncstatusdraft'] = 'Preparing';
$string['syncstatusdraft_desc'] = 'The meeting is stored locally and is waiting to be queued.';
$string['syncstatusfailed'] = 'Synchronization failed';
$string['syncstatusfailed_desc'] = 'The last synchronization attempt failed. A teacher or administrator can inspect the '
    . 'diagnostic details.';
$string['syncstatuspending'] = 'Creating conference';
$string['syncstatuspending_desc'] = 'The Calendar event exists and Google is still creating the Meet conference.';
$string['syncstatusqueued'] = 'Queued';
$string['syncstatusqueued_desc'] = 'The meeting is waiting for a background synchronization worker.';
$string['syncstatusready'] = 'Ready';
$string['syncstatusready_desc'] = 'The Calendar event and Google Meet conference are synchronized.';
$string['syncstatussyncing'] = 'Synchronizing';
$string['syncstatussyncing_desc'] = 'Moodle is reconciling the meeting with Google Calendar.';
$string['synchronizationstatus'] = 'Synchronization status';
$string['syncdiagnosticdetails'] = 'Technical details';
$string['syncerrorcode'] = 'Error code';
$string['syncerrormessage'] = 'Safe error message';
$string['synclastattempt'] = 'Last attempt: {$a}';
$string['synchronisetask'] = 'Synchronize Google Meet activity';
$string['thereisnorecordingtoshow'] = 'There is no recording to show.';
$string['timeahead'] = 'Is not possible to create multiple recurrences of the event date that exceed one year, adjust the start and end dates.';
$string['timedate'] = '%d/%m/%Y %H:%M';
$string['to'] = 'to';
$string['today'] = 'Today';
$string['upcomingevents'] = 'Upcoming events';
$string['url'] = '';
$string['url_failed'] = 'A valid Google Meet URL is required';
$string['url_help'] = 'E.g. https://meet.google.com/aaa-aaaa-aaa';
$string['visible'] = 'Visible';
$string['week'] = 'Week(s)';
$string['recordingissuerid'] = 'OAuth service for recordings';
$string['recordingissuerid_desc'] = 'Select a dedicated Google OAuth service for recording discovery. It must be '
    . 'different from the Calendar/login service and request only the Google Meet read-only scope when a teacher '
    . 'connects recordings.';
$string['recordingowneronly'] = 'Only the teacher who connected recording discovery can synchronize this activity.';
$string['recordingsoauthconnect'] = 'Connect Google Meet recordings';
$string['recordingsoauthconnected'] = 'Google Meet recording discovery is connected for this teacher.';
$string['recordingsoauthfailed'] = 'Google Meet recording authorization could not be completed.';
$string['recordingsoauthrequired'] = 'The recording owner must reconnect Google Meet before discovery can continue.';
$string['recordingsoauthunavailable'] = 'Configure a dedicated Google OAuth service for recording discovery. It must '
    . 'be different from the Calendar/login service.';
$string['recordingsactivitymissing'] = 'Recording discovery skipped missing Google Meet activity {$a}.';
$string['recordingsapifailed'] = 'Google Meet rejected the recording discovery request.';
$string['recordingsdiscovertask'] = 'Discover Google Meet recordings';
$string['recordingsinvalidaction'] = 'The requested recording action is invalid.';
$string['recordingsqueued'] = 'Recording discovery was queued.';
$string['recordingsresponseinvalid'] = 'Google Meet returned an invalid recording response.';
$string['recordingsstateskipped'] = 'Recording discovery skipped activity {$a->id} in state {$a->state}.';
$string['recordingssync'] = 'Discover recordings';
$string['recordingsynced'] = 'Recording discovery completed for activity {$a->id}: {$a->count} generated artifact(s) found.';
$string['recordingsyncstatus'] = 'Recording discovery status';
$string['recordingsyncstatusdisconnected'] = 'Disconnected';
$string['recordingsyncstatusfailed'] = 'Failed';
$string['recordingsyncstatusqueued'] = 'Queued';
$string['recordingsyncstatusready'] = 'Ready';
$string['recordingsyncstatussyncing'] = 'Synchronizing';
