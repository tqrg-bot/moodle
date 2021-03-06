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
 * Contains class used to return information to display for the message area.
 *
 * @package    core_message
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_message;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/messagelib.php');

/**
 * Class used to return information to display for the message area.
 *
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /**
     * Handles searching for messages in the message area.
     *
     * @param int $userid The user id doing the searching
     * @param string $search The string the user is searching
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function search_messages($userid, $search, $limitfrom = 0, $limitnum = 0) {
        global $DB;

        // Get the user fields we want.
        $ufields = \user_picture::fields('u', array('lastaccess'), 'userfrom_id', 'userfrom_');
        $ufields2 = \user_picture::fields('u2', array('lastaccess'), 'userto_id', 'userto_');

        // Get all the messages for the user.
        $sql = "SELECT m.id, m.useridfrom, m.useridto, m.subject, m.fullmessage, m.fullmessagehtml, m.fullmessageformat,
                       m.smallmessage, m.notification, m.timecreated, 0 as isread, $ufields, mc.blocked as userfrom_blocked,
                       $ufields2, mc2.blocked as userto_blocked
                  FROM {message} m
                  JOIN {user} u
                    ON m.useridfrom = u.id
             LEFT JOIN {message_contacts} mc
                    ON (mc.contactid = u.id AND mc.userid = ?)
                  JOIN {user} u2
                    ON m.useridto = u2.id
             LEFT JOIN {message_contacts} mc2
                    ON (mc2.contactid = u2.id AND mc2.userid = ?)
                 WHERE ((useridto = ? AND timeusertodeleted = 0)
                    OR (useridfrom = ? AND timeuserfromdeleted = 0))
                   AND notification = 0
                   AND u.deleted = 0
                   AND u2.deleted = 0
                   AND " . $DB->sql_like('smallmessage', '?', false) . "
             UNION ALL
                SELECT mr.id, mr.useridfrom, mr.useridto, mr.subject, mr.fullmessage, mr.fullmessagehtml, mr.fullmessageformat,
                       mr.smallmessage, mr.notification, mr.timecreated, 1 as isread, $ufields, mc.blocked as userfrom_blocked,
                       $ufields2, mc2.blocked as userto_blocked
                  FROM {message_read} mr
                  JOIN {user} u
                    ON mr.useridfrom = u.id
             LEFT JOIN {message_contacts} mc
                    ON (mc.contactid = u.id AND mc.userid = ?)
                  JOIN {user} u2
                    ON mr.useridto = u2.id
             LEFT JOIN {message_contacts} mc2
                    ON (mc2.contactid = u2.id AND mc2.userid = ?)
                 WHERE ((useridto = ? AND timeusertodeleted = 0)
                    OR (useridfrom = ? AND timeuserfromdeleted = 0))
                   AND notification = 0
                   AND u.deleted = 0
                   AND u2.deleted = 0
                   AND " . $DB->sql_like('smallmessage', '?', false) . "
              ORDER BY timecreated DESC";
        $params = array($userid, $userid, $userid, $userid, '%' . $search . '%',
                        $userid, $userid, $userid, $userid, '%' . $search . '%');

        // Convert the messages into searchable contacts with their last message being the message that was searched.
        $conversations = array();
        if ($messages = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum)) {
            foreach ($messages as $message) {
                $prefix = 'userfrom_';
                if ($userid == $message->useridfrom) {
                    $prefix = 'userto_';
                    // If it from the user, then mark it as read, even if it wasn't by the receiver.
                    $message->isread = true;
                }
                $blockedcol = $prefix . 'blocked';
                $message->blocked = $message->$blockedcol;

                $message->messageid = $message->id;
                $conversations[] = helper::create_contact($message, $prefix);
            }
        }

        return $conversations;
    }

    /**
     * Handles searching for user in a particular course in the message area.
     *
     * @param int $userid The user id doing the searching
     * @param int $courseid The id of the course we are searching in
     * @param string $search The string the user is searching
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function search_users_in_course($userid, $courseid, $search, $limitfrom = 0, $limitnum = 0) {
        global $DB;

        // Get all the users in the course.
        list($esql, $params) = get_enrolled_sql(\context_course::instance($courseid), '', 0, true);
        $sql = "SELECT u.*, mc.blocked
                  FROM {user} u
                  JOIN ($esql) je
                    ON je.id = u.id
             LEFT JOIN {message_contacts} mc
                    ON (mc.contactid = u.id AND mc.userid = :userid)
                 WHERE u.deleted = 0";
        // Add more conditions.
        $fullname = $DB->sql_fullname();
        $sql .= " AND u.id != :userid2
                  AND " . $DB->sql_like($fullname, ':search', false) . "
             ORDER BY " . $DB->sql_fullname();
        $params = array_merge(array('userid' => $userid, 'userid2' => $userid, 'search' => '%' . $search . '%'), $params);

        // Convert all the user records into contacts.
        $contacts = array();
        if ($users = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum)) {
            foreach ($users as $user) {
                $contacts[] = helper::create_contact($user);
            }
        }

        return $contacts;
    }

    /**
     * Handles searching for user in the message area.
     *
     * @param int $userid The user id doing the searching
     * @param string $search The string the user is searching
     * @param int $limitnum
     * @return array
     */
    public static function search_users($userid, $search, $limitnum = 0) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/lib/coursecatlib.php');

        // Used to search for contacts.
        $fullname = $DB->sql_fullname();
        $ufields = \user_picture::fields('u', array('lastaccess'));

        // Users not to include.
        $excludeusers = array($userid, $CFG->siteguest);
        list($exclude, $excludeparams) = $DB->get_in_or_equal($excludeusers, SQL_PARAMS_NAMED, 'param', false);

        // Ok, let's search for contacts first.
        $contacts = array();
        $sql = "SELECT $ufields, mc.blocked
                  FROM {user} u
                  JOIN {message_contacts} mc
                    ON u.id = mc.contactid
                 WHERE mc.userid = :userid
                   AND u.deleted = 0
                   AND u.confirmed = 1
                   AND " . $DB->sql_like($fullname, ':search', false) . "
                   AND u.id $exclude
              ORDER BY " . $DB->sql_fullname();
        if ($users = $DB->get_records_sql($sql, array('userid' => $userid, 'search' => '%' . $search . '%') + $excludeparams,
            0, $limitnum)) {
            foreach ($users as $user) {
                $contacts[] = helper::create_contact($user);
            }
        }

        // Now, let's get the courses.
        // Make sure to limit searches to enrolled courses.
        $enrolledcourses = enrol_get_my_courses(array('id', 'cacherev'));
        $courses = array();
        if ($arrcourses = \coursecat::search_courses(array('search' => $search), array('limit' => $limitnum),
                array('moodle/course:viewparticipants'))) {
            foreach ($arrcourses as $course) {
                if (isset($enrolledcourses[$course->id])) {
                    $data = new \stdClass();
                    $data->id = $course->id;
                    $data->shortname = $course->shortname;
                    $data->fullname = $course->fullname;
                    $courses[] = $data;
                }
            }
        }

        // Let's get those non-contacts. Toast them gears boi.
        // Note - you can only block contacts, so these users will not be blocked, so no need to get that
        // extra detail from the database.
        $noncontacts = array();
        $sql = "SELECT $ufields
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.confirmed = 1
                   AND " . $DB->sql_like($fullname, ':search', false) . "
                   AND u.id $exclude
                   AND u.id NOT IN (SELECT contactid
                                      FROM {message_contacts}
                                     WHERE userid = :userid)
              ORDER BY " . $DB->sql_fullname();
        if ($users = $DB->get_records_sql($sql,  array('userid' => $userid, 'search' => '%' . $search . '%') + $excludeparams,
            0, $limitnum)) {
            foreach ($users as $user) {
                $noncontacts[] = helper::create_contact($user);
            }
        }

        return array($contacts, $courses, $noncontacts);
    }

    /**
     * Returns the contacts and their conversation to display in the contacts area.
     *
     * @param int $userid The user id
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function get_conversations($userid, $limitfrom = 0, $limitnum = 0) {
        $arrconversations = array();
        if ($conversations = message_get_recent_conversations($userid, $limitfrom, $limitnum)) {
            foreach ($conversations as $conversation) {
                $arrconversations[$conversation->id] = helper::create_contact($conversation);
            }
        }

        return $arrconversations;
    }

    /**
     * Returns the contacts to display in the contacts area.
     *
     * @param int $userid The user id
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function get_contacts($userid, $limitfrom = 0, $limitnum = 0) {
        global $DB;

        $arrcontacts = array();
        $sql = "SELECT u.*, mc.blocked
                  FROM {message_contacts} mc
                  JOIN {user} u
                    ON mc.contactid = u.id
                 WHERE mc.userid = :userid
                   AND u.deleted = 0
              ORDER BY " . $DB->sql_fullname();
        if ($contacts = $DB->get_records_sql($sql, array('userid' => $userid), $limitfrom, $limitnum)) {
            foreach ($contacts as $contact) {
                $arrcontacts[] = helper::create_contact($contact);
            }
        }

        return $arrcontacts;
    }

    /**
     * Returns the messages to display in the message area.
     *
     * @param int $userid the current user
     * @param int $otheruserid the other user
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $sort
     * @param int $timefrom the time from the message being sent
     * @param int $timeto the time up until the message being sent
     * @return array
     */
    public static function get_messages($userid, $otheruserid, $limitfrom = 0, $limitnum = 0,
        $sort = 'timecreated ASC', $timefrom = 0, $timeto = 0) {

        if (!empty($timefrom)) {
            // Check the cache to see if we even need to do a DB query.
            $cache = \cache::make('core', 'message_time_last_message_between_users');
            $key = helper::get_last_message_time_created_cache_key($otheruserid, $userid);
            $lastcreated = $cache->get($key);

            // The last known message time is earlier than the one being requested so we can
            // just return an empty result set rather than having to query the DB.
            if ($lastcreated && $lastcreated < $timefrom) {
                return [];
            }
        }

        $arrmessages = array();
        if ($messages = helper::get_messages($userid, $otheruserid, 0, $limitfrom, $limitnum,
                                             $sort, $timefrom, $timeto)) {

            $arrmessages = helper::create_messages($userid, $messages);
        }

        return $arrmessages;
    }

    /**
     * Returns the most recent message between two users.
     *
     * @param int $userid the current user
     * @param int $otheruserid the other user
     * @return \stdClass|null
     */
    public static function get_most_recent_message($userid, $otheruserid) {
        // We want two messages here so we get an accurate 'blocktime' value.
        if ($messages = helper::get_messages($userid, $otheruserid, 0, 0, 2, 'timecreated DESC')) {
            // Swap the order so we now have them in historical order.
            $messages = array_reverse($messages);
            $arrmessages = helper::create_messages($userid, $messages);
            return array_pop($arrmessages);
        }

        return null;
    }

    /**
     * Returns the profile information for a contact for a user.
     *
     * @param int $userid The user id
     * @param int $otheruserid The id of the user whose profile we want to view.
     * @return \stdClass
     */
    public static function get_profile($userid, $otheruserid) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');

        if ($user = \core_user::get_user($otheruserid)) {
            // Create the data we are going to pass to the renderable.
            $userfields = user_get_user_details($user, null, array('city', 'country', 'email',
                'profileimageurl', 'profileimageurlsmall', 'lastaccess'));
            if ($userfields) {
                $data = new \stdClass();
                $data->userid = $userfields['id'];
                $data->fullname = $userfields['fullname'];
                $data->city = isset($userfields['city']) ? $userfields['city'] : '';
                $data->country = isset($userfields['country']) ? $userfields['country'] : '';
                $data->email = isset($userfields['email']) ? $userfields['email'] : '';
                $data->profileimageurl = isset($userfields['profileimageurl']) ? $userfields['profileimageurl'] : '';
                if (isset($userfields['profileimageurlsmall'])) {
                    $data->profileimageurlsmall = $userfields['profileimageurlsmall'];
                } else {
                    $data->profileimageurlsmall = '';
                }
                if (isset($userfields['lastaccess'])) {
                    $data->isonline = helper::is_online($userfields['lastaccess']);
                } else {
                    $data->isonline = false;
                }
            } else {
                // Technically the access checks in user_get_user_details are correct,
                // but messaging has never obeyed them. In order to keep messaging working
                // we at least need to return a minimal user record.
                $data = new \stdClass();
                $data->userid = $otheruserid;
                $data->fullname = fullname($user);
                $data->city = '';
                $data->country = '';
                $data->email = '';
                $data->profileimageurl = '';
                $data->profileimageurlsmall = '';
                $data->isonline = false;
            }
            // Check if the contact has been blocked.
            $contact = $DB->get_record('message_contacts', array('userid' => $userid, 'contactid' => $otheruserid));
            if ($contact) {
                $data->isblocked = (bool) $contact->blocked;
                $data->iscontact = true;
            } else {
                $data->isblocked = false;
                $data->iscontact = false;
            }

            return $data;
        }
    }

    /**
     * Checks if a user can delete messages they have either received or sent.
     *
     * @param int $userid The user id of who we want to delete the messages for (this may be done by the admin
     *  but will still seem as if it was by the user)
     * @return bool Returns true if a user can delete the conversation, false otherwise.
     */
    public static function can_delete_conversation($userid) {
        global $USER;

        $systemcontext = \context_system::instance();

        // Let's check if the user is allowed to delete this conversation.
        if (has_capability('moodle/site:deleteanymessage', $systemcontext) ||
            ((has_capability('moodle/site:deleteownmessage', $systemcontext) &&
                $USER->id == $userid))) {
            return true;
        }

        return false;
    }

    /**
     * Deletes a conversation.
     *
     * This function does not verify any permissions.
     *
     * @param int $userid The user id of who we want to delete the messages for (this may be done by the admin
     *  but will still seem as if it was by the user)
     * @param int $otheruserid The id of the other user in the conversation
     * @return bool
     */
    public static function delete_conversation($userid, $otheruserid) {
        global $DB;

        // We need to update the tables to mark all messages as deleted from and to the other user. This seems worse than it
        // is, that's because our DB structure splits messages into two tables (great idea, huh?) which causes code like this.
        // This won't be a particularly heavily used function (at least I hope not), so let's hope MDL-36941 gets worked on
        // soon for the sake of any developers' sanity when dealing with the messaging system.
        $now = time();
        $sql = "UPDATE {message}
                   SET timeuserfromdeleted = :time
                 WHERE useridfrom = :userid
                   AND useridto = :otheruserid
                   AND notification = 0";
        $DB->execute($sql, array('time' => $now, 'userid' => $userid, 'otheruserid' => $otheruserid));

        $sql = "UPDATE {message}
                   SET timeusertodeleted = :time
                 WHERE useridto = :userid
                   AND useridfrom = :otheruserid
                   AND notification = 0";
        $DB->execute($sql, array('time' => $now, 'userid' => $userid, 'otheruserid' => $otheruserid));

        $sql = "UPDATE {message_read}
                   SET timeuserfromdeleted = :time
                 WHERE useridfrom = :userid
                   AND useridto = :otheruserid
                   AND notification = 0";
        $DB->execute($sql, array('time' => $now, 'userid' => $userid, 'otheruserid' => $otheruserid));

        $sql = "UPDATE {message_read}
                   SET timeusertodeleted = :time
                 WHERE useridto = :userid
                   AND useridfrom = :otheruserid
                   AND notification = 0";
        $DB->execute($sql, array('time' => $now, 'userid' => $userid, 'otheruserid' => $otheruserid));

        // Now we need to trigger events for these.
        if ($messages = helper::get_messages($userid, $otheruserid, $now)) {
            // Loop through and trigger a deleted event.
            foreach ($messages as $message) {
                $messagetable = 'message';
                if (!empty($message->timeread)) {
                    $messagetable = 'message_read';
                }

                // Trigger event for deleting the message.
                \core\event\message_deleted::create_from_ids($message->useridfrom, $message->useridto,
                    $userid, $messagetable, $message->id)->trigger();
            }
        }

        return true;
    }

    /**
     * Returns the count of unread conversations (collection of messages from a single user) for
     * the given user.
     *
     * @param \stdClass $user the user who's conversations should be counted
     * @return int the count of the user's unread conversations
     */
    public static function count_unread_conversations($user = null) {
        global $USER, $DB;

        if (empty($user)) {
            $user = $USER;
        }

        return $DB->count_records_select(
            'message',
            'useridto = ? AND timeusertodeleted = 0 AND notification = 0',
            [$user->id],
            "COUNT(DISTINCT(useridfrom))");
    }

    /**
     * Marks ALL messages being sent from $fromuserid to $touserid as read.
     *
     * Can be filtered by type.
     *
     * @param int $touserid the id of the message recipient
     * @param int $fromuserid the id of the message sender
     * @param string $type filter the messages by type, either MESSAGE_TYPE_NOTIFICATION, MESSAGE_TYPE_MESSAGE or '' for all.
     * @return void
     */
    public static function mark_all_read_for_user($touserid, $fromuserid = 0, $type = '') {
        global $DB;

        $params = array();

        if (!empty($touserid)) {
            $params['useridto'] = $touserid;
        }

        if (!empty($fromuserid)) {
            $params['useridfrom'] = $fromuserid;
        }

        if (!empty($type)) {
            if (strtolower($type) == MESSAGE_TYPE_NOTIFICATION) {
                $params['notification'] = 1;
            } else if (strtolower($type) == MESSAGE_TYPE_MESSAGE) {
                $params['notification'] = 0;
            }
        }

        $messages = $DB->get_recordset('message', $params);

        foreach ($messages as $message) {
            message_mark_message_read($message, time());
        }

        $messages->close();
    }

    /**
     * Returns message preferences.
     *
     * @param array $processors
     * @param array $providers
     * @param \stdClass $user
     * @return \stdClass
     * @since 3.2
     */
    public static function get_all_message_preferences($processors, $providers, $user) {
        $preferences = helper::get_providers_preferences($providers, $user->id);
        $preferences->userdefaultemail = $user->email; // May be displayed by the email processor.

        // For every processors put its options on the form (need to get function from processor's lib.php).
        foreach ($processors as $processor) {
            $processor->object->load_data($preferences, $user->id);
        }

        // Load general messaging preferences.
        $preferences->blocknoncontacts = get_user_preferences('message_blocknoncontacts', '', $user->id);
        $preferences->mailformat = $user->mailformat;
        $preferences->mailcharset = get_user_preferences('mailcharset', '', $user->id);

        return $preferences;
    }

    /**
     * Count the number of users blocked by a user.
     *
     * @param \stdClass $user The user object
     * @return int the number of blocked users
     */
    public static function count_blocked_users($user = null) {
        global $USER, $DB;

        if (empty($user)) {
            $user = $USER;
        }

        $sql = "SELECT count(mc.id)
                  FROM {message_contacts} mc
                 WHERE mc.userid = :userid AND mc.blocked = 1";
        return $DB->count_records_sql($sql, array('userid' => $user->id));
    }

    /**
     * Determines if a user is permitted to send another user a private message.
     * If no sender is provided then it defaults to the logged in user.
     *
     * @param \stdClass $recipient The user object.
     * @param \stdClass|null $sender The user object.
     * @return bool true if user is permitted, false otherwise.
     */
    public static function can_post_message($recipient, $sender = null) {
        global $USER;

        if (is_null($sender)) {
            // The message is from the logged in user, unless otherwise specified.
            $sender = $USER;
        }

        if (!has_capability('moodle/site:sendmessage', \context_system::instance(), $sender)) {
            return false;
        }

        // The recipient blocks messages from non-contacts and the
        // sender isn't a contact.
        if (self::is_user_non_contact_blocked($recipient, $sender)) {
            return false;
        }

        // The recipient has specifically blocked this sender.
        if (self::is_user_blocked($recipient, $sender)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if the recipient is allowing messages from users that aren't a
     * contact. If not then it checks to make sure the sender is in the
     * recipient's contacts.
     *
     * @param \stdClass $recipient The user object.
     * @param \stdClass|null $sender The user object.
     * @return bool true if $sender is blocked, false otherwise.
     */
    public static function is_user_non_contact_blocked($recipient, $sender = null) {
        global $USER, $DB;

        if (is_null($sender)) {
            // The message is from the logged in user, unless otherwise specified.
            $sender = $USER;
        }

        $blockednoncontacts = get_user_preferences('message_blocknoncontacts', '', $recipient->id);
        if (!empty($blockednoncontacts)) {
            // Confirm the sender is a contact of the recipient.
            $exists = $DB->record_exists('message_contacts', array('userid' => $recipient->id, 'contactid' => $sender->id));
            if ($exists) {
                // All good, the recipient is a contact of the sender.
                return false;
            } else {
                // Oh no, the recipient is not a contact. Looks like we can't send the message.
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the recipient has specifically blocked the sending user.
     *
     * Note: This function will always return false if the sender has the
     * readallmessages capability at the system context level.
     *
     * @param object $recipient User object.
     * @param object $sender User object.
     * @return bool true if $sender is blocked, false otherwise.
     */
    public static function is_user_blocked($recipient, $sender = null) {
        global $USER, $DB;

        if (is_null($sender)) {
            // The message is from the logged in user, unless otherwise specified.
            $sender = $USER;
        }

        $systemcontext = \context_system::instance();
        if (has_capability('moodle/site:readallmessages', $systemcontext, $sender)) {
            return false;
        }

        if ($contact = $DB->get_record('message_contacts', array('userid' => $recipient->id, 'contactid' => $sender->id))) {
            if ($contact->blocked) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get specified message processor, validate corresponding plugin existence and
     * system configuration.
     *
     * @param string $name  Name of the processor.
     * @param bool $ready only return ready-to-use processors.
     * @return mixed $processor if processor present else empty array.
     * @since Moodle 3.2
     */
    public static function get_message_processor($name, $ready = false) {
        global $DB, $CFG;

        $processor = $DB->get_record('message_processors', array('name' => $name));
        if (empty($processor)) {
            // Processor not found, return.
            return array();
        }

        $processor = self::get_processed_processor_object($processor);
        if ($ready) {
            if ($processor->enabled && $processor->configured) {
                return $processor;
            } else {
                return array();
            }
        } else {
            return $processor;
        }
    }

    /**
     * Returns weather a given processor is enabled or not.
     * Note:- This doesn't check if the processor is configured or not.
     *
     * @param string $name Name of the processor
     * @return bool
     */
    public static function is_processor_enabled($name) {

        $cache = \cache::make('core', 'message_processors_enabled');
        $status = $cache->get($name);

        if ($status === false) {
            $processor = self::get_message_processor($name);
            if (!empty($processor)) {
                $cache->set($name, $processor->enabled);
                return $processor->enabled;
            } else {
                return false;
            }
        }

        return $status;
    }

    /**
     * Set status of a processor.
     *
     * @param \stdClass $processor processor record.
     * @param 0|1 $enabled 0 or 1 to set the processor status.
     * @return bool
     * @since Moodle 3.2
     */
    public static function update_processor_status($processor, $enabled) {
        global $DB;
        $cache = \cache::make('core', 'message_processors_enabled');
        $cache->delete($processor->name);
        return $DB->set_field('message_processors', 'enabled', $enabled, array('id' => $processor->id));
    }

    /**
     * Given a processor object, loads information about it's settings and configurations.
     * This is not a public api, instead use @see \core_message\api::get_message_processor()
     * or @see \get_message_processors()
     *
     * @param \stdClass $processor processor object
     * @return \stdClass processed processor object
     * @since Moodle 3.2
     */
    public static function get_processed_processor_object(\stdClass $processor) {
        global $CFG;

        $processorfile = $CFG->dirroot. '/message/output/'.$processor->name.'/message_output_'.$processor->name.'.php';
        if (is_readable($processorfile)) {
            include_once($processorfile);
            $processclass = 'message_output_' . $processor->name;
            if (class_exists($processclass)) {
                $pclass = new $processclass();
                $processor->object = $pclass;
                $processor->configured = 0;
                if ($pclass->is_system_configured()) {
                    $processor->configured = 1;
                }
                $processor->hassettings = 0;
                if (is_readable($CFG->dirroot.'/message/output/'.$processor->name.'/settings.php')) {
                    $processor->hassettings = 1;
                }
                $processor->available = 1;
            } else {
                print_error('errorcallingprocessor', 'message');
            }
        } else {
            $processor->available = 0;
        }
        return $processor;
    }
}
