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
$string['issuerid_desc'] = 'Select the Google OAuth service used by teachers for managed Calendar meetings. The '
    . 'flow requests event management and read-only Calendar-list scopes; existing grants must reconnect once after '
    . 'this upgrade. <a href="https://github.com/ronefel/moodle-mod_googlemeet/wiki/'
    . 'How-to-create-Client-ID-and-Client-Secret" target="_blank" rel="noopener">How to set up an OAuth service</a>.';
$string['calendareventname'] = '{$a} is scheduled for';
$string['checkweekdays'] = 'Select at least one weekday for the recurrence.';
$string['date'] = 'Date';
$string['deleterecordingreferences'] = 'Remove recording references';
$string['deleterecordingreferencesconfirm'] = 'Remove every recording reference from this Moodle activity? '
    . 'The files in Google Drive will not be deleted. A later discovery may add the references again.';
$string['deleterecordingreferencestitle'] = 'Remove recording references?';
$string['duration'] = 'Duration';
$string['editrecordingname'] = 'Edit recording name';
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
$string['googlemeet:receivecalendarinvite'] = 'Receive Google Calendar meeting invitations';
$string['googlemeet:receivenotification'] = 'Receive meeting reminders';
$string['googlemeet:removerecording'] = 'Remove recordings';
$string['googlemeet:syncgoogledrive'] = 'Discover Google Meet recordings';
$string['googlemeet:view'] = 'View Google Meet™ for Moodle content';
$string['hide'] = 'Hide';
$string['invalidactivitycontext'] = 'The requested item does not belong to this Google Meet activity.';
$string['invalidrecordingname'] = 'Enter a recording name containing no more than 255 characters.';
$string['invalideventenddate'] = 'The recurrence end cannot be earlier than the meeting start.';
$string['invalideventendtime'] = 'The end time must be greater than start time';
$string['invalidissuerid'] = 'The OAuth service selected in the "Google Meet™ for Moodle" settings is not supported by Google';
$string['invalidintegrationmode'] = 'Select a supported meeting integration mode.';
$string['invalidguestpolicy'] = 'Select a supported Google Calendar invitation policy.';
$string['invalidmeetingtimezone'] = 'Select a valid IANA timezone.';
$string['invalidrecurrenceinterval'] = 'The recurrence interval must be between 1 and 36 weeks.';
$string['invalidschedule'] = 'The meeting schedule is invalid. Review its dates, timezone and recurrence.';
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
$string['guestlimitexceeded'] = 'This activity can invite at most {$a} active participants. Reduce the eligible '
    . 'enrolments or keep Calendar invitations disabled.';
$string['guestpolicy'] = 'Google Calendar invitations';
$string['guestpolicy_help'] = 'Choose explicitly whether active enrolled users with the Calendar invitation '
    . 'capability become attendees of the Google Calendar event. Invitations, updates and removals can send email.';
$string['guestpolicycourse'] = 'Invite active course participants';
$string['guestpolicynone'] = 'Do not add course participants to Google Calendar';
$string['guestpolicywarning'] = 'Saving this option can send Google Calendar email to as many as {$a} active '
    . 'participants. Moodle reconciles only users with the dedicated invitation capability and never sends a '
    . 'partial list above this limit.';
$string['jstableinfo'] = 'Showing {start} to {end} of {rows} recordings';
$string['jstableinfofiltered'] = 'Showing {start} to {end} of {rows} recordings (filtered from {rowsTotal} recordings)';
$string['jstableloading'] = 'Loading...';
$string['jstablenorows'] = 'No recording found';
$string['jstableperpage'] = '{select} recordings per page';
$string['jstablesearch'] = 'Search...';
$string['joinafterminutes'] = 'Participant access after meeting';
$string['joinafterminutes_desc'] = 'Keep the server-side join gateway available for this long after each occurrence '
    . 'ends. Teachers with permission to manage the meeting are not restricted by this time window.';
$string['joinbeforeminutes'] = 'Participant early access';
$string['joinbeforeminutes_desc'] = 'Open the server-side join gateway this long before each occurrence starts. '
    . 'Teachers with permission to manage the meeting are not restricted by this time window.';
$string['lastsync'] = 'Last sync:';
$string['loading'] = 'Loading';
$string['logintoaccount'] = 'Log in to your Google account';
$string['logintoyourgoogleaccount'] = 'Log in to your Google account so that the Google Meet URL can be automatically created';
$string['loggedinaccount'] = 'Connected Google account';
$string['logout'] = 'Logout';
$string['manage'] = 'Manage';
$string['managedmodecannotchange'] = 'A managed meeting cannot be converted to a manual link while its remote Calendar '
    . 'lifecycle is active.';
$string['managedcalendar'] = 'Google Calendar';
$string['managedcalendar_help'] = 'Choose a calendar where this teacher can create events. The selection is verified '
    . 'against the connected Google account before the activity is saved and becomes read-only after the managed '
    . 'activity is attached, preventing an existing event from being redirected.';
$string['managedcalendarlockedoption'] = '{$a} (attached to this managed activity)';
$string['managedcalendarnone'] = 'No writable Google Meet-compatible calendars are available for this account.';
$string['managedcalendaroption'] = '{$a->summary} — {$a->id}';
$string['managedcalendarotherowner'] = 'A calendar is attached to this managed activity. Its identifier is visible '
    . 'only to the teacher who owns the Google authorization.';
$string['managedcalendarpreflightunavailable'] = 'Moodle could not verify writable Google calendars right now. Try '
    . 'again before saving the managed meeting.';
$string['managedcalendarprimary'] = '(primary)';
$string['managedcalendarrequired'] = 'Select a writable Google Calendar before saving a managed meeting.';
$string['managedcalendarunavailable'] = 'The selected Google Calendar is unavailable or this teacher can no longer '
    . 'create events in it. Moodle did not switch the activity to another calendar.';
$string['managedoauth'] = 'Google Calendar authorization';
$string['managedoauthclose'] = 'You can close this window and return to the activity form.';
$string['managedoauthconnect'] = 'Connect Google Calendar';
$string['managedoauthconnected'] = 'Google Calendar is connected for this teacher.';
$string['managedoauthfailed'] = 'Google Calendar authorization could not be completed.';
$string['managedoauthreauthorizerequired'] = 'Reconnect Google Calendar to approve read-only access to your calendar '
    . 'list. Event access remains limited to creating and managing events.';
$string['managedoauthreconnect'] = 'Reconnect Google Calendar';
$string['managedoauthrequired'] = 'Connect your Google account before saving a managed meeting.';
$string['managedoauthunavailable'] = 'A configured Google OAuth service is required for managed meetings.';
$string['managedowneronly'] = 'Only the teacher who owns this managed meeting can update its Google Calendar integration.';
$string['managedroomurldesc'] = 'Managed meetings receive their Meet link after background synchronization. Enter a link '
    . 'only when using manual mode.';
$string['meetingaccessatend'] = 'At the meeting end';
$string['meetingaccessatstart'] = 'At the meeting start';
$string['meetingaccessclosed'] = 'The participant access window for this meeting has closed.';
$string['meetingaccessinvalidschedule'] = 'Moodle could not safely evaluate this meeting schedule. A teacher or '
    . 'administrator must correct the activity before participant access can continue.';
$string['meetingaccessscheduled'] = 'The meeting link will become available at {$a}.';
$string['meetingend'] = 'Meeting end';
$string['meetingend_help'] = 'Select the exact end date and time. It must be later than the meeting start.';
$string['meetinglinknotready'] = 'The Google Meet link is not available yet. The activity will update after synchronization.';
$string['meetingschedule'] = 'Meeting schedule';
$string['meetingstart'] = 'Meeting start';
$string['meetingstart_help'] = 'Select the exact start date and time in the meeting timezone.';
$string['meetingtimezone'] = 'Meeting timezone';
$string['meetingtimezone_help'] = 'Dates are stored as absolute timestamps. Weekly recurrences preserve the selected '
    . 'local time in this timezone across daylight-saving changes.';
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
$string['notifytaskresult'] = 'Meeting reminders: {$a->events} due occurrence(s), {$a->sent} message(s) sent.';
$string['or'] = 'or';
$string['overviewjoinmeeting'] = 'Join meeting';
$string['overviewmeetingtime'] = 'Meeting time';
$string['overviewsyncstatus'] = 'Meeting status';
$string['play'] = 'Play';
$string['pluginadministration'] = 'Google Meet™ for Moodle administration';
$string['pluginname'] = 'Google Meet™ for Moodle';
$string['privacy:metadata:core_calendar'] = 'The activity uses Moodle Calendar to publish its local schedule.';
$string['privacy:metadata:core_message'] = 'The activity uses Moodle messaging to send configured meeting reminders.';
$string['privacy:metadata:core_oauth2'] = 'Moodle core stores the per-user OAuth grant and tokens used by this activity.';
$string['privacy:metadata:google_calendar'] = 'A connected teacher account sends event and conference data to Google Calendar.';
$string['privacy:metadata:google_calendar:authorizedaccount'] = 'The Google account authorized by the teacher.';
$string['privacy:metadata:google_calendar:calendarlist'] = 'Calendar identifiers, names, access roles and conferencing '
    . 'support read from the connected account to select a writable destination.';
$string['privacy:metadata:google_calendar:conference'] = 'The request to create a Google Meet conference.';
$string['privacy:metadata:google_calendar:attendees'] = 'Email addresses of active course participants explicitly '
    . 'selected as Calendar attendees.';
$string['privacy:metadata:google_calendar:recurrence'] = 'The event recurrence rule.';
$string['privacy:metadata:google_calendar:schedule'] = 'The event start and end.';
$string['privacy:metadata:google_calendar:summary'] = 'The Moodle activity name used as the event summary.';
$string['privacy:metadata:google_calendar:timezone'] = 'The event timezone.';
$string['privacy:metadata:google_meet'] = 'A connected teacher account sends a meeting code to Google Meet to '
    . 'discover generated recording metadata.';
$string['privacy:metadata:google_meet:authorizedaccount'] = 'The Google account authorized by the teacher.';
$string['privacy:metadata:google_meet:meetingcode'] = 'The exact meeting code used to find conference records.';
$string['privacy:metadata:google_meet:recordings'] = 'Generated recording identifiers, timestamps and playback links.';
$string['privacy:metadata:googlemeet'] = 'Stores owner-scoped authorization references and operational synchronization data.';
$string['privacy:metadata:googlemeet:calendarid'] = 'The selected Google Calendar identifier.';
$string['privacy:metadata:googlemeet:conferenceid'] = 'The Google conference identifier.';
$string['privacy:metadata:googlemeet:conferencestatus'] = 'The conference creation state returned by Google.';
$string['privacy:metadata:googlemeet:creatoremail'] = 'The legacy Google organizer email.';
$string['privacy:metadata:googlemeet:eventid'] = 'The legacy external Calendar link identifier.';
$string['privacy:metadata:googlemeet:googleeventetag'] = 'The Calendar event entity tag.';
$string['privacy:metadata:googlemeet:googleeventhtmlurl'] = 'The Google Calendar event page.';
$string['privacy:metadata:googlemeet:googleeventid'] = 'The Google Calendar event identifier.';
$string['privacy:metadata:googlemeet:lasterrorcode'] = 'The safe code from the last Calendar synchronization error.';
$string['privacy:metadata:googlemeet:lasterrormessage'] = 'The sanitized message from the last Calendar synchronization error.';
$string['privacy:metadata:googlemeet:meetingcode'] = 'The normalized Google Meet meeting code.';
$string['privacy:metadata:googlemeet:meetinguri'] = 'The Google Meet join link.';
$string['privacy:metadata:googlemeet:oauthissuerid'] = 'The Moodle OAuth service used by the Calendar owner.';
$string['privacy:metadata:googlemeet:owneruserid'] = 'The Moodle user who owns the Calendar authorization.';
$string['privacy:metadata:googlemeet:recordinglasterrorcode'] = 'The safe code from the last recording discovery error.';
$string['privacy:metadata:googlemeet:recordinglasterrormessage'] = 'The sanitized message from the last recording discovery error.';
$string['privacy:metadata:googlemeet:recordingoauthissuerid'] = 'The Moodle OAuth service used by the recording owner.';
$string['privacy:metadata:googlemeet:recordingowneruserid'] = 'The Moodle user who owns recording discovery authorization.';
$string['privacy:metadata:googlemeet:recordingsyncattempts'] = 'The number of recording discovery attempts.';
$string['privacy:metadata:googlemeet:recordingsyncstatus'] = 'The recording discovery state.';
$string['privacy:metadata:googlemeet:recordingtimelastattempt'] = 'The time of the last recording discovery attempt.';
$string['privacy:metadata:googlemeet:requestid'] = 'The idempotency identifier used to request a conference.';
$string['privacy:metadata:googlemeet:syncattempts'] = 'The number of Calendar synchronization attempts.';
$string['privacy:metadata:googlemeet:syncstatus'] = 'The Calendar synchronization state.';
$string['privacy:metadata:googlemeet:timelastattempt'] = 'The time of the last Calendar synchronization attempt.';
$string['privacy:metadata:googlemeet:guestcount'] = 'The number of attendees managed during the last Calendar update.';
$string['privacy:metadata:googlemeet:guesthash'] = 'A stable hash of the last managed Calendar attendee set.';
$string['privacy:metadata:googlemeet:guesttimechecked'] = 'The time Moodle last checked eligible course participants.';
$string['privacy:metadata:googlemeet:guesttimelastsync'] = 'The time Moodle last changed managed Calendar attendees.';
$string['privacy:metadata:googlemeet_calendar_guests'] = 'Stores the bounded local receipt of attendees managed from '
    . 'active Moodle enrolments.';
$string['privacy:metadata:googlemeet_calendar_guests:emailhash'] = 'A one-way normalized hash of the attendee email.';
$string['privacy:metadata:googlemeet_calendar_guests:timemodified'] = 'The time the managed attendee receipt changed.';
$string['privacy:metadata:googlemeet_calendar_guests:userid'] = 'The Moodle user represented by the managed attendee.';
$string['privacy:metadata:googlemeet_notify_done'] = 'Stores receipts for meeting reminders sent to users.';
$string['privacy:metadata:googlemeet_notify_done:eventid'] = 'The local event associated with the reminder.';
$string['privacy:metadata:googlemeet_notify_done:timesent'] = 'The time when the reminder was sent.';
$string['privacy:metadata:googlemeet_notify_done:userid'] = 'The Moodle user who received the reminder.';
$string['privacy:metadata:googlemeet_recordings'] = 'Stores shared references to generated recordings published in the activity.';
$string['privacy:metadata:googlemeet_recordings:createdtime'] = 'The recording creation time.';
$string['privacy:metadata:googlemeet_recordings:duration'] = 'The recording duration.';
$string['privacy:metadata:googlemeet_recordings:name'] = 'The displayed recording name.';
$string['privacy:metadata:googlemeet_recordings:recordingid'] = 'The Google Drive file identifier returned by Google Meet.';
$string['privacy:metadata:googlemeet_recordings:visible'] = 'Whether the recording is visible to course participants.';
$string['privacy:metadata:googlemeet_recordings:webviewlink'] = 'The Google Drive playback link returned by Google Meet.';
$string['privacy:path:calendar'] = 'Google Calendar authorization and synchronization';
$string['privacy:path:calendarattendee'] = 'Managed Google Calendar invitation';
$string['privacy:path:notifications'] = 'Meeting reminder receipts';
$string['privacy:path:recordingauthorization'] = 'Google Meet recording authorization';
$string['privacy:path:recordings'] = 'Shared recording references discovered with your authorization';
$string['recording'] = 'Recording';
$string['recordings'] = 'Recordings';
$string['recordingswiththename'] = 'Recordings with the name:';
$string['reconcilependingresult'] = 'Calendar reconciliation found {$a->found} meeting(s) and queued {$a->queued}.';
$string['reconcilependingtask'] = 'Reconcile pending Google Meet conferences';
$string['reconcileguestsresult'] = 'Calendar guest check inspected {$a->found} meeting(s), queued {$a->queued} and '
    . 'found {$a->unchanged} unchanged.';
$string['reconcilegueststask'] = 'Reconcile Google Calendar guests';
$string['recurrenceeventdate'] = 'Meeting recurrence';
$string['recurrenceeventdate_help'] = 'Enable weekly recurrence, select one or more weekdays, choose an interval from '
    . '1 to 36 weeks, and set the inclusive recurrence boundary. A series can span at most one year.';
$string['recurrencecompatibilitynotice'] = 'This imported schedule contains individual recurrence dates or exceptions '
    . 'which the weekly editor cannot represent safely. Its canonical schedule has been preserved as read-only; the '
    . 'other activity settings can still be edited.';
$string['recurrenceenabled'] = 'Repeat this meeting weekly';
$string['recurrenceinterval'] = 'Repeat every (weeks)';
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
$string['syncguestlimitexceeded'] = 'Google Calendar invitations are limited to {$a} attendees so Moodle never sends '
    . 'a partial or unexpectedly large invitation list.';
$string['syncconferencecreationfailed'] = 'Google Calendar could not create the Google Meet conference.';
$string['synccancel'] = 'Cancel meeting';
$string['synccancelqueued'] = 'The meeting cancellation was queued.';
$string['syncdisconnect'] = 'Disconnect this activity';
$string['syncdisconnected'] = 'The local Google Calendar authorization and remote identifiers were removed.';
$string['syncdisconnectrequirescancel'] = 'Cancel the Google Calendar event successfully before disconnecting this activity.';
$string['syncinvalidintegrationmode'] = 'The stored Google Meet integration mode is invalid.';
$string['synclegacydisconnected'] = 'Google Meet activity {$a} requires Google reconnection.';
$string['synclocktimeout'] = 'Could not acquire the synchronization lock for Google Meet activity {$a}.';
$string['syncmanagedapifailed'] = 'Google Meet activity {$a} was rejected by Google Calendar.';
$string['syncmanagedcancelled'] = 'Google Meet activity {$a} was cancelled in Google Calendar.';
$string['syncmanagedconfigurationfailed'] = 'Google Meet activity {$a} has invalid managed Calendar configuration.';
$string['syncmanageddeferred'] = 'Google Meet activity {$a} is waiting for the managed Google Calendar adapter.';
$string['syncmanageddisconnected'] = 'Google Meet activity {$a} requires fresh Google Calendar authorization.';
$string['syncmanagedfailed'] = 'Google Meet activity {$a} received a failed conference creation result.';
$string['syncmanagedguestlimitfailed'] = 'Google Meet activity {$a} exceeded the Calendar guest safety limit.';
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
$string['timeahead'] = 'A recurring meeting cannot exceed one year. Adjust the start or recurrence end.';
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
$string['recordingname'] = 'Recording name';
$string['recordingplaybackunavailable'] = 'Recording playback is unavailable.';
$string['playrecording'] = 'Play recording';
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
$string['recordingsdisconnect'] = 'Disconnect recording discovery';
$string['recordingsdisconnected'] = 'Recording discovery was disconnected. Existing recording references were preserved.';
$string['recordingsinvalidaction'] = 'The requested recording action is invalid.';
$string['recordingsqueued'] = 'Recording discovery was queued.';
$string['recordingsresponseinvalid'] = 'Google Meet returned an invalid recording response.';
$string['recordingsstateskipped'] = 'Recording discovery skipped activity {$a->id} in state {$a->state}.';
$string['recordingssync'] = 'Discover recordings';
$string['recordingssynced'] = 'Recording discovery completed for activity {$a->id}: {$a->count} generated artifact(s) found.';
$string['recordingsyncstatus'] = 'Recording discovery status';
$string['recordingsyncstatusdisconnected'] = 'Disconnected';
$string['recordingsyncstatusfailed'] = 'Failed';
$string['recordingsyncstatusqueued'] = 'Queued';
$string['recordingsyncstatusready'] = 'Ready';
$string['recordingsyncstatussyncing'] = 'Synchronizing';
$string['diagnosticretentiondays'] = 'Operational diagnostic retention';
$string['diagnosticretentiondays_desc'] = 'Keep privacy-safe integration transitions for this many days. The daily '
    . 'purge removes at most 5,000 expired rows per run.';
$string['diagnosticsactivityid'] = 'Activity ID';
$string['diagnosticscode'] = 'Diagnostic code';
$string['diagnosticscount'] = 'Count';
$string['diagnosticslatest'] = 'Latest operational transitions (up to {$a})';
$string['diagnosticsnone'] = 'No operational transitions match these filters.';
$string['diagnosticsoperation'] = 'Operation';
$string['diagnosticsoperation_guest_reconcile'] = 'Calendar guest reconciliation';
$string['diagnosticsoperation_meeting_cancel'] = 'Calendar cancellation';
$string['diagnosticsoperation_meeting_sync'] = 'Calendar synchronization';
$string['diagnosticsoperation_recording_discovery'] = 'Recording discovery';
$string['diagnosticsoutcome'] = 'Outcome';
$string['diagnosticsoutcome_blocked'] = 'Action required';
$string['diagnosticsoutcome_failed'] = 'Failed';
$string['diagnosticsoutcome_pending'] = 'Pending';
$string['diagnosticsoutcome_queued'] = 'Queued';
$string['diagnosticsoutcome_retrying'] = 'Retrying';
$string['diagnosticsoutcome_started'] = 'Started';
$string['diagnosticsoutcome_succeeded'] = 'Succeeded';
$string['diagnosticspagetitle'] = 'Google Meet operational diagnostics';
$string['diagnosticsprivacyboundary'] = 'This view never stores or displays OAuth tokens, user IDs, email addresses, '
    . 'free-form error messages or Google response bodies. Rows are retained for {$a} days.';
$string['diagnosticspurgeresult'] = 'Operational diagnostics retained for {$a->days} days; {$a->deleted} expired '
    . 'row(s) were removed.';
$string['diagnosticspurgetask'] = 'Purge Google Meet operational diagnostics';
$string['diagnosticssource'] = 'Source';
$string['diagnosticssource_adhoc'] = 'Owner-scoped task';
$string['diagnosticssource_cron'] = 'Scheduled task';
$string['diagnosticssource_user'] = 'User action';
$string['diagnosticssummary24h'] = 'Outcomes in the last 24 hours';
$string['diagnosticstime'] = 'Time';
$string['eventoperationrecorded'] = 'Integration operation recorded';
$string['googlemeet:viewdiagnostics'] = 'View Google Meet operational diagnostics';
