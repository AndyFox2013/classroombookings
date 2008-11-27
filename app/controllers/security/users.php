<?php
/*
	This file is part of Classroombookings.

	Classroombookings is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Classroombookings is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with Classroombookings.  If not, see <http://www.gnu.org/licenses/>.
*/


class Users extends Controller {


	var $tpl;
	
	
	function Users(){
		parent::Controller();
		$this->load->model('security');
		$this->tpl = $this->config->item('template');
		#$this->output->enable_profiler(TRUE);
	}
	
	
	
	
	function index(){
		$icondata[0] = array('security/users/add', 'Add a new user', 'plus.gif' );
		$icondata[1] = array('security/users/import', 'Import from file', 'database-arr.gif' );
		$icondata[2] = array('security/groups', 'Manage groups', 'group.gif' );
		$icondata[3] = array('security/permissions', 'Change group permissions', 'key2.gif');
		$tpl['pretitle'] = $this->load->view('parts/iconbar', $icondata, TRUE);
		
		// Get list of users
		$body['users'] = $this->security->get_user();
		if ($body['users'] == FALSE) {
			$tpl['body'] = $this->msg->err($this->security->lasterr);
		} else {
			$tpl['body'] = $this->load->view('security/users.index.php', $body, TRUE);
		}
		
		$tpl['title'] = 'Users';
		$tpl['pagetitle'] = 'Manage users';
		
		$this->load->view($this->tpl, $tpl);
	}
	
	
	
	
	function ingroup($group_id){
		$icondata[0] = array('security/users/add', 'Add a new user', 'plus.gif' );
		$icondata[1] = array('security/users/import', 'Import', 'database-arr.gif' );
		$icondata[2] = array('security/groups', 'Manage groups', 'group.gif' );
		$icondata[3] = array('security/permissions', 'Change group permissions', 'key2.gif');
		$tpl['pretitle'] = $this->load->view('parts/iconbar', $icondata, TRUE);
		
		$tpl['title'] = 'Users';
		$groupname = $this->security->get_group_name($group_id);
		if ($groupname == FALSE) {
			$tpl['body'] = $this->msg->err($this->security->lasterr);
			$tpl['pagetitle'] = $tpl['title'];
		} else {
			$body['users'] = $this->security->get_user(NULL, $group_id);
			if ($body['users'] === FALSE) {
				$tpl['body'] = $this->msg->err($this->security->lasterr);
			} else {
				$tpl['body'] = $this->load->view('security/users.index.php', $body, TRUE);
			}
			$tpl['pagetitle'] = sprintf('Manage users in the %s group', $groupname);
		}
		
		$this->load->view($this->tpl, $tpl);
	}
	
	
	
	
	function add(){
		$body['user'] = NULL;
		$body['user_id'] = NULL;
		$body['groups'] = $this->security->get_groups_dropdown();
		$tpl['title'] = 'Add user';
		$tpl['pagetitle'] = 'Add a new user';
		$tpl['body'] = $this->load->view('security/users.addedit.php', $body, TRUE);
		$this->load->view($this->tpl, $tpl);
	}
	
	
	
	
	function edit($user_id){
		$body['user'] = $this->security->get_user($user_id);
		$body['user_id'] = $user_id;
		$body['groups'] = $this->security->get_groups_dropdown();
		
		$tpl['title'] = 'Edit user';
		$tpl['pagetitle'] = ($body['user']->displayname == FALSE) ? 'Edit ' . $body['user']->username : 'Edit ' . $body['user']->displayname;
		$tpl['body'] = $this->load->view('security/users.addedit.php', $body, TRUE);
		
		$this->load->view($this->tpl, $tpl);
	}
	
	
	
	function save(){
		#die(print_r($_POST));
		
		$user_id = $this->input->post('user_id');
		
		$this->form_validation->set_rules('user_id', 'User ID');
		$this->form_validation->set_rules('username', 'Username', 'required|max_length[64]|trim');
		if(!$user_id){
			$this->form_validation->set_rules('password1', 'Password', 'max_length[104]|required');
			$this->form_validation->set_rules('password2', 'Password (confirmation)', 'max_length[104]|required|matches[password1]');
		}
		$this->form_validation->set_rules('group_id', 'Group', 'required|integer');
		$this->form_validation->set_rules('enabled', 'Enabled', 'exact_length[1]');
		$this->form_validation->set_rules('email', 'Email address', 'max_length[256]|valid_email|trim');
		$this->form_validation->set_rules('displayname', 'Display name', 'max_length[64]|trim');
		$this->form_validation->set_rules('department_id', 'Department', 'integer');
		$this->form_validation->set_error_delimiters('<li>', '</li>');

		if($this->form_validation->run() == FALSE){
			
			// Validation failed
			
			($user_id == NULL) ? $this->add() : $this->edit($user_id);
			
		} else {
		
			// Validation OK
			
			$data['username'] = $this->input->post('username');
			$data['displayname'] = $this->input->post('displayname');
			$data['email'] = $this->input->post('email');
			$data['group_id'] = $this->input->post('group_id');
			$data['department_id'] = $this->input->post('department_id');
			$data['enabled'] = ($this->input->post('enabled') == '1') ? 1 : 0;
			// Only set password if supplied.
			if($this->input->post('password1')){
				$data['password'] = sha1($this->input->post('password1'));
			}
			
			if($user_id == NULL){
			
				// Adding user
				$data['ldap'] = 0;
				
				#die(var_export($data, true));
				$add = $this->security->add_user($data);
				
				if($add == TRUE){
					$message = ($data['enabled'] == 1) ? 'SECURITY_USER_ADD_OK_ENABLED' : 'SECURITY_USER_ADD_OK_DISABLED';
					$this->msg->add('info', $this->lang->line($message));
				} else {
					$this->msg->add('err', sprintf($this->lang->line('SECURITY_USER_ADD_FAIL', $this->security->lasterr)));
				}
			
			} else {
			
				// Updating existing user
				$edit = $this->security->edit_user($user_id, $data);
				if($edit == TRUE){
					$message = ($data['enabled'] == 1) ? 'SECURITY_USER_EDIT_OK_ENABLED' : 'SECURITY_USER_EDIT_OK_DISABLED';
					$this->msg->add('info', $this->lang->line($message));
				} else {
					$this->msg->add('err', sprintf($this->lang->line('SECURITY_USER_EDIT_FAIL', $this->security->lasterr)));
				}
				
			}
			
			// All done, redirect!
			redirect('security/users');
			
		}
		
	}
	
	
	
	
}


?>