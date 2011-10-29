<?php
// This file is not a part of Moodle - http://moodle.org/
// This is a none core contributed module.
//
// This is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// The GNU General Public License
// can be see at <http://www.gnu.org/licenses/>.

/**
 * Invitation enrolment plugin.
 *
 * This plugin allows you to send invitation by email. These invitations can be used only once. Users
 * clicking on the email link are automatically enrolled.
 *
 * @package    enrol
 * @subpackage invitation
 * @copyright  2011 Jerome Mouneyrac {@link http://www.moodleitandme.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Invitation enrolment plugin implementation.
 * @author  Jerome Mouneyrac
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_invitation_plugin extends enrol_plugin {

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        return array(new pix_icon('icon', get_string('pluginname', 'enrol_invitation'), 'enrol_invitation'));
    }

    public function roles_protected() {
        // users with role assign cap may tweak the roles later
        return false;
    }

    public function allow_unenrol(stdClass $instance) {
        // users with unenrol cap may unenrol other users manually - requires enrol/invitation:unenrol
        return true;
    }

    public function allow_manage(stdClass $instance) {
        // users with manage cap may tweak period and status - requires enrol/invitation:manage
        return true;
    }
    
     /**
     * Attempt to automatically enrol current user in course without any interaction,
     * calling code has to make sure the plugin and instance are active.
     *
     * This should return either a timestamp in the future or false.
     *
     * @param stdClass $instance course enrol instance
     * @param stdClass $user record
     * @return bool|int false means not enrolled, integer means timeend
     */
    public function try_autoenrol(stdClass $instance) {
        global $USER;

        return false;
    }
    
     

    /**
     * Sets up navigation entries.
     *
     * @param object $instance
     * @return void
     */
    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'invitation') {
             throw new coding_exception('Invalid enrol instance type!');
        }

        $context = get_context_instance(CONTEXT_COURSE, $instance->courseid);
        if (has_capability('enrol/invitation:config', $context)) {
            $managelink = new moodle_url('/enrol/invitation/edit.php', array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $instancesnode->add($this->get_instance_name($instance), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Returns edit icons for the page with list of instances
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        if ($instance->enrol !== 'invitation') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = get_context_instance(CONTEXT_COURSE, $instance->courseid);

        $icons = array();

        if (has_capability('enrol/invitation:config', $context)) {
            $editlink = new moodle_url("/enrol/invitation/edit.php", array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('i/edit', get_string('edit'), 'core', array('class'=>'icon')));
        }

        return $icons;
    }

    /**
     * ???
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = get_context_instance(CONTEXT_COURSE, $courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) or !has_capability('enrol/invitation:config', $context)) {
            return NULL;
        }

        // multiple instances supported - different cost for different roles
        return new moodle_url('/enrol/invitation/edit.php', array('courseid'=>$courseid));
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    function enrol_page_hook(stdClass $instance) {
        global $DB, $USER, $OUTPUT, $CFG, $SITE;
        
        //check if param token exist
        $enrolinvitationtoken = optional_param('enrolinvitationtoken', '', PARAM_ALPHANUM);

        if (!empty($enrolinvitationtoken)) {
            
            $id = required_param('id', PARAM_INT);
        
            //retrieve the token info
            $invitation = $DB->get_record('enrol_invitation', 
                    array('token' => $enrolinvitationtoken, 'tokenused' => false));

             //if token is valid, enrol the user into the course          
            if (empty($invitation) or ($invitation->courseid != $id)) {
                throw new moodle_exception('expiredtoken', 'enrol_invitation');
            }

            //First multiple check related to the invitation plugin config

            if (isguestuser()) {
                // can not enrol guest!!
                return null;
            }
            if ($DB->record_exists('user_enrolments', array('userid'=>$USER->id, 'enrolid'=>$instance->id))) {
                //TODO: maybe we should tell them they are already enrolled, but can not access the course
                return null;
            }

            if ($instance->enrolstartdate != 0 and $instance->enrolstartdate > time()) {
                //TODO: inform that we can not enrol yet
                return null;
            }

            if ($instance->enrolenddate != 0 and $instance->enrolenddate < time()) {
                //TODO: inform that enrolment is not possible any more
                return null;
            }
            
            if (empty($instance->roleid)) {
                return null;
            }

            //enrol the user into the course
            require_once($CFG->dirroot . '/enrol/invitation/locallib.php');
            $invitationmanager = new invitation_manager($invitation->courseid);
            $invitationmanager->enroluser();
            
            //Set token as used
            $invitation->tokenused = true;
            $invitation->timeused = time();
            $invitation->userid = $USER->id;
            $DB->update_record('enrol_invitation', $invitation);
            
            //send an email to the user who sent the invitation        
            $teacher = $DB->get_record('user', array('id' => $invitation->creatorid));
            $contactuser = new object;
            $contactuser->email = $teacher->email;
            $contactuser->firstname = $teacher->firstname;
            $contactuser->lastname = $teacher->lastname;
            $contactuser->maildisplay = true;
            $emailinfo = new stdClass();
            $emailinfo->userfullname = $USER->firstname . ' ' . $USER->lastname;
            $courseenrolledusersurl = new moodle_url('/enrol/users.php', array('id' => $invitation->courseid));
            $emailinfo->courseenrolledusersurl = $courseenrolledusersurl->out(false);
            $course = $DB->get_record('course', array('id' => $invitation->courseid)); 
            $emailinfo->coursefullname = $course->fullname;
            $emailinfo->sitename = $SITE->fullname;
            $siteurl = new moodle_url('/');
            $emailinfo->siteurl = $siteurl->out(false);
            email_to_user($contactuser, get_admin(),
                            get_string('emailtitleuserenrolled', 'enrol_invitation', $emailinfo),
                            get_string('emailmessageuserenrolled', 'enrol_invitation', $emailinfo));
        }
        
    }
    
    //// ????
    
    /**
     * Returns an enrol_user_button that takes the user to a page where they are able to
     * enrol users into the managers course through this plugin.
     *
     * Optional: If the plugin supports manual enrolments it can choose to override this
     * otherwise it shouldn't
     *
     * @param course_enrolment_manager $manager
     * @return enrol_user_button|false
     */
    public function get_manual_enrol_button(course_enrolment_manager $manager) {
        global $CFG;
        
        $instance = null;
        $instances = array();
        foreach ($manager->get_enrolment_instances() as $tempinstance) {
            if ($tempinstance->enrol == 'invitation') {
                if ($instance === null) {
                    $instance = $tempinstance;
                }
                $instances[] = array('id' => $tempinstance->id, 'name' => $this->get_instance_name($tempinstance));
            }
        }
        if (empty($instance)) {
            return false;
        }
        
        $context = get_context_instance(CONTEXT_COURSE, $instance->courseid);
        if (has_capability('enrol/invitation:enrol', $context)) {
            $invitelink = new moodle_url('/enrol/invitation/invitation.php', 
                array('courseid'=>$instance->courseid, 'id'=>$instance->id));
            $button = new enrol_user_button($invitelink, 
                    get_string('inviteusers', 'enrol_invitation'), 'post');
            return $button;
        } else {
            return false;
        }
    }
    
    

    /**
     * Gets an array of the user enrolment actions
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol($instance) && has_capability("enrol/invitation:unenrol", $context)) {
            $url = new moodle_url('/enrol/invitation/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class'=>'unenrollink', 'rel'=>$ue->id));
        }
        if ($this->allow_manage($instance) && has_capability("enrol/invitation:manage", $context)) {
            $url = new moodle_url('/enrol/invitation/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''), get_string('edit'), $url, array('class'=>'editenrollink', 'rel'=>$ue->id));
        }
        return $actions;
    }

    /**
     * Returns true if the plugin has one or more bulk operations that can be performed on
     * user enrolments.
     *
     * @return bool
     */
    public function has_bulk_operations() {
       return false;
    }

    /**
     * Return an array of enrol_bulk_enrolment_operation objects that define
     * the bulk actions that can be performed on user enrolments by the plugin.
     *
     * @return array
     */
    public function get_bulk_operations() {
        return array();
    }

}