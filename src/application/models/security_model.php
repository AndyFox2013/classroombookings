<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/*
 * Classroombookings. Hassle-free resource booking for schools. <http://classroombookings.com/>
 * Copyright (C) 2006-2011 Craig A Rodway <craig.rodway@gmail.com>
 *
 * This file is part of Classroombookings.
 * Classroombookings is licensed under the Affero GNU GPLv3 license.
 * Please see license-classroombookings.txt for the full license text.
 */

class Security_model extends CI_Model
{


	var $lasterr;
	
	
	function __construct()
	{
		echo "You should not be loading the security model!";
		parent::__construct();
	}
	
	
	
	
	/**
	 * Users
	 * =====
	 */
	
	
	
	
	/**
	 * get one or more users (optionally by group)
	 *
	 * @param int user_id
	 * @param int group_id
	 * @param arr pagination limit,start
	 * @return mixed (object on success, false on failure)
	 *
	 * Example - get one user
	 *   get_user(42);
	 *
	 * Example - get all users
	 *   get_user();
	 *
	 * Example - get all users in a group
	 *  get_user(NULL, 4);
	 */
	function get_user($user_id = NULL, $group_id = NULL, $page = NULL)
	{
		if ($user_id == NULL)
		{
			// Getting all users
			$this->db->select(
				'users.*,
				IFNULL(users.displayname, users.username) AS displayname, 
				groups.name AS groupname, 
				IF(usersactive.timestamp > 0, true, false) AS online'
			, false);
			$this->db->from('users');
			$this->db->join('groups', 'users.group_id = groups.group_id', 'left');
			$this->db->join('quota', 'users.user_id = quota.user_id', 'left');
			$this->db->join('usersactive', 'users.user_id = usersactive.user_id', 'left');
			
			// Filter to group if necessary
			if ($group_id != NULL && is_numeric($group_id))
			{
				$this->db->where('users.group_id', $group_id);
			}
			
			$this->db->order_by('users.username ASC');
			
			if (isset($page) && is_array($page))
			{
				$this->db->limit($page[0], $page[1]);
			}
			
			$query = $this->db->get();
			if ($query->num_rows() > 0)
			{
				return $query->result();
			}
			else
			{
				$this->lasterr = 'This group is empty!';
				return 0;
			}
			
		}
		else
		{
			
			if (!is_numeric($user_id))
			{
				return FALSE;
			}
			
			// Getting one user
			$sql = 'SELECT 
						users.user_id, 
						users.group_id,
						users.enabled,
						users.username,
						users.email,
						IFNULL(users.displayname, users.username) AS displayname,
						users.lastlogin,
						users.lastactivity,
						users.ldap,
						users.created,
						GROUP_CONCAT(DISTINCT(u2d.department_id)) AS departments
					FROM users
					LEFT JOIN groups ON users.group_id = groups.group_id
					LEFT JOIN quota ON users.user_id = quota.user_id
					LEFT JOIN users2departments u2d ON users.user_id = u2d.user_id
					WHERE users.user_id = ?
					GROUP BY users.user_id
					LIMIT 1';
			$query = $this->db->query($sql, array($user_id));
			
			if ($query->num_rows() == 1)
			{
				$user = $query->row();
				$user->departments = explode(',', $user->departments);
				#$user->display2 = ($user->displayname) ? $user->displayname : $user->username;
				return $user;
			}
			else
			{
				return FALSE;
			}
			
		}
		
	}
	
	
	
	
	/**
	 * Get users in format for a dropdown box (id => name)
	 *
	 * @param	bool	none	Include a "(None)" option with a value of -1
	 * @return	Array	Array: user_id => Display name
	 */
	function get_users_dropdown($none = FALSE)
	{
		$sql = 'SELECT user_id, username, displayname, IFNULL(displayname, username) AS display
				FROM users
				ORDER BY display ASC';
		$query = $this->db->query($sql);
		if ($query->num_rows() > 0)
		{
			$result = $query->result();
			$users = array();
			if ($none == TRUE)
			{
				$users[-1] = '(None)';
			}
			foreach ($result as $user)
			{
				$users[$user->user_id] = $user->display;
			}
			return $users;
		}
		else
		{
			$this->lasterr = 'No users found';
			return FALSE;
		}
	}
	
	
	
	
	/**
	 * Add a user to the database
	 */
	function add_user($data)
	{
		// Check if user exists - can't add if already in DB
		$exists = $this->auth->userexists($data['username']);
		if ($exists == true)
		{
			$this->lasterr = 'Username already exists.';
			return false;
		}
		
		// Add a date created value
		$data['created'] = date("Y-m-d");
		
		// Get supplied departments if any, and then remove from data array
		$departments = (isset($data['departments'])) ? $data['departments'] : array();
		unset($data['departments']);
		
		// Hash and store password securely
		$data['password'] = $this->auth->hash_password($data['password']);
		
		$add = $this->db->insert('users', $data);
		$user_id = $this->db->insert_id();
		
		// Update the deparments for the user now we have an ID
		$this->update_user_departments($user_id, $departments);
		
		return ($add == true) ? $user_id : false;
	}
	
	
	
	
	/**
	 * Update user details
	 */
	function edit_user($user_id = null, $data = array())
	{
		if($user_id == null)
		{
			$this->lasterr = 'Cannot update a user without their ID.';
			return false;
		}
		
		// Get supplied departments if any, and then remove from data array
		$departments = (isset($data['departments'])) ? $data['departments'] : array();
		unset($data['departments']);
		
		// Hash and store password securely
		if (!empty($data['password']))
		{
			$data['password'] = $this->auth->hash_password($data['password']);
		}
		
		$this->db->where('user_id', $user_id);
		$edit = $this->db->update('users', $data);
		
		$this->update_user_departments($user_id, $departments);
		
		return $edit;
	}
	
	
	
	
	/**
	 * Count the total number of users
	 *
	 * @return	int		Number of users in the DB
	 */
	function total_users(){
		$sql = 'SELECT user_id FROM users';
		$query = $this->db->query($sql);
		return $query->num_rows();
	}
	
	
	
	
	/**
	 * Re-assign users to departments
	 *
	 * @param	int		user_id			User ID
	 * @param	array	departments		Array of department IDs to associate with user
	 */
	function update_user_departments($user_id, $departments = array()){
		// Remove LDAP department assignments (don't panic; will re-insert if they are specified)
		$sql = 'DELETE FROM users2departments WHERE user_id = ?';
		$query = $this->db->query($sql, array($user_id));
		
		#print_r($departments);
		#echo var_dump(count($departments));
		
		// If LDAP groups were assigned then insert into DB
		if(!empty($departments)){
			$sql = 'INSERT INTO users2departments (user_id, department_id) VALUES ';
			foreach($departments as $department_id){
				$sql .= sprintf("(%d,%d),", $user_id, $department_id);
			}
			// Remove last comma
			$sql = preg_replace('/,$/', '', $sql);
			$query = $this->db->query($sql);
			if($query == FALSE){
				$this->lasterr = 'Could not assign departments to user.';
				return FALSE;
			} else {
				return TRUE;
			}
		}
	}
	
	
	
	
	/**
	 * Delete a user from the DB
	 *
	 * @param	int		user_id		User ID
	 * @return	bool	True on successful deletion
	 */
	function delete_user($user_id){
		
		$sql = 'DELETE FROM users WHERE user_id = ? LIMIT 1';
		$query = $this->db->query($sql, array($user_id));
		
		if($query == FALSE){
			
			$this->lasterr = 'Could not delete user. Do they exist?';
			return FALSE;
			
		} else {
			
			/* $sql = 'DELETE FROM bookings WHERE user_id = ?';
			$query = $this->db->query($sql, array($user_id));
			if($query == FALSE){ $failed[] = 'bookings'; }*/
			
			$sql = 'UPDATE rooms SET user_id = NULL WHERE user_id = ?';
			$query = $this->db->query($sql, array($user_id));
			if($query == FALSE){ $failed[] = 'rooms'; }
			
			if(isset($failed)){
				$this->lasterr = 'The user was deleted successfully, but an error occured while removing their bookings and/or updating any rooms they owned.';
			}
			
			return TRUE;
			
		}
		
	}
	
	
	
	
	/**
	 * Get one or more groups
	 *
	 * @param	int		group_id	Specify if wanting one group. NULL to return all groups.
	 * @param	array	page		Pagination array (start,limit)
	 * @return	array
	 */
	function get_group($group_id = NULL, $page = NULL){
		if ($group_id == NULL) {
		
			// Getting all groups and number of users in it
			$this->db->select('
				groups.*,
				(
					SELECT COUNT(user_id)
					FROM users
					WHERE groups.group_id = users.group_id
					LIMIT 1
				) AS usercount',
				FALSE
			);
			$this->db->from('groups');
						
			$this->db->order_by('groups.name ASC');
			
			if (isset($page) && is_array($page)) {
				$this->db->limit($page[0], $page[1]);
			}
			
			$query = $this->db->get();
			if ($query->num_rows() > 0){
				return $query->result();
			} else {
				$this->lasterr = 'No groups available.';
				return 0;
			}
			
		} else {
			
			if (!is_numeric($group_id)) {
				return FALSE;
			}
			
			// Getting one group
			$sql = 'SELECT * FROM groups WHERE group_id = ? LIMIT 1';
			$query = $this->db->query($sql, array($group_id));
			
			if($query->num_rows() == 1){
				
				// Got the group!
				$group = $query->row();
				$group->ldapgroups = array();
				
				// Fetch the LDAP groups that are mapped (if any)
				$sql = 'SELECT ldapgroup_id FROM groups2ldapgroups WHERE group_id = ?';
				$query = $this->db->query($sql, array($group_id));
				if($query->num_rows() > 0){
					$ldapgroups = array();
					foreach($query->result() as $row){
						array_push($ldapgroups, $row->ldapgroup_id);
					}
					// Assign array of LDAP groups to main group object that is to be returned
					$group->ldapgroups = $ldapgroups;
					unset($ldapgroups);
				}
				
				return $group;
				
			} else {
				
				return FALSE;
				
			}
			
		}
		
	}
	
	
	
	
	/**
	 * Add a group to the database
	 *
	 * @param	array	data	Array of group data to insert
	 * @return	bool
	 */
	function add_group($data){
		// Add created date to the array to be inserted into the DB
		$data['created'] = date("Y-m-d");
		
		// If no LDAP groups, set empty array. Otherwise assign to new array for itself
		if(in_array(-1, $data['ldapgroups'])){
			$ldapgroups = array();
		} else {
			$ldapgroups = $data['ldapgroups'];
		}
		
		// Remove ldapgroups from the main data array (no 'ldapgroups' column)
		unset($data['ldapgroups']);
		
		// Add the user and get the ID
		$add = $this->db->insert('groups', $data);
		$group_id = $this->db->insert_id();
		
		// If LDAP groups were assigned then insert into DB now we have the group ID
		if(count($ldapgroups) > 0){
			$sql = 'INSERT INTO groups2ldapgroups (group_id, ldapgroup_id) VALUES ';
			foreach($ldapgroups as $ldapgroup_id){
				$sql .= sprintf("(%d,%d),", $group_id, $ldapgroup_id);
			}
			// Remove last comma
			$sql = preg_replace('/,$/', '', $sql);
			$query = $this->db->query($sql);
			if($query == FALSE){
				$this->lasterr = 'Could not assign LDAP groups';
			}
		}
		
		return $group_id;
		
	}
	
	
	
	
	/**
	 * Update data for a group
	 *
	 * @param	int		group_id	Group ID
	 * @param	array	data		Data
	 */
	function edit_group($group_id = NULL, $data){
		// Gotta have an ID
		if($group_id == NULL){
			$this->lasterr = 'Cannot update a group without their ID.';
			return FALSE;
		}
		
		// If no LDAP groups, set empty array. Otherwise assign to new array for itself
		if(in_array(-1, $data['ldapgroups'])){
			$ldapgroups = array();
		} else {
			$ldapgroups = $data['ldapgroups'];
		}
		
		unset($data['ldapgroups']);
		
		// Update group main details
		$this->db->where('group_id', $group_id);
		$edit = $this->db->update('groups', $data);
		
		// Now remove LDAP group assignments (don't panic - will now re-insert if they are specified)
		$sql = 'DELETE FROM groups2ldapgroups WHERE group_id = ?';
		$query = $this->db->query($sql, array($group_id));
		
		// If LDAP groups were assigned then insert into DB
		if(count($ldapgroups) > 0){
			$sql = 'INSERT INTO groups2ldapgroups (group_id, ldapgroup_id) VALUES ';
			foreach($ldapgroups as $ldapgroup_id){
				$sql .= sprintf("(%d,%d),", $group_id, $ldapgroup_id);
			}
			// Remove last comma
			$sql = preg_replace('/,$/', '', $sql);
			$query = $this->db->query($sql);
			if($query == FALSE){
				$this->lasterr = 'Could not assign LDAP groups';
			}
		}
		
		
		return $edit;
	}
	
	
	
	
	function delete_group($group_id){
	
		if($group_id == 0 OR $group_id == 1){
			$this->lasterr = 'Cannot delete that default group.';
			return FALSE;
		}
		
		$sql = 'DELETE FROM groups WHERE group_id = ? LIMIT 1';
		$query = $this->db->query($sql, array($group_id));
		
		if($query == FALSE){
			
			$this->lasterr = 'Could not delete group. Do they exist?';
			return FALSE;
			
		} else {
			
			/* $sql = 'DELETE FROM bookings WHERE user_id = ?';
			$query = $this->db->query($sql, array($user_id));
			if($query == FALSE){ $failed[] = 'bookings'; }*/
			
			// Remove LDAP group assignments so the LDAP groups can be assigned to another group
			$sql = 'DELETE FROM groups2ldapgroups WHERE group_id = ?';
			$query = $this->db->query($sql, array($group_id));
			if($query == FALSE){
				$failed['ldapgroups'] = 'Failed to remove LDAP group assignments';
			}
			
			// Remove users in this group and put them into Guests
			$sql = 'UPDATE users SET group_id = 0 WHERE group_id = ?';
			$query = $this->db->query($sql, array($group_id));
			if($query == FALSE){
				$failed['users'] = 'Failed to re-assign users in the group you deleted to the default Guests group';
			}
			
			// Check if our sub-actions failed
			if(isset($failed)){
				$this->lasterr = 'The group was deleted successfully, but other errors occured: <ul>';
				foreach($failed as $k => $v){
					$this->lasterr .= sprintf('<li>%s</li>', $v);
				}
				$this->lasterr .= '</ul>';
				return FALSE;
			}
			
			return TRUE;
			
		}
		
	}
	
	
	
	
	function get_groups_dropdown(){
		$sql = 'SELECT group_id, name FROM groups ORDER BY name ASC';
		$query = $this->db->query($sql);
		if($query->num_rows() > 0){
			$result = $query->result();
			$groups = array();
			foreach($result as $group){
				$groups[$group->group_id] = $group->name;
			}
			return $groups;
		} else {
			$this->lasterr = 'No groups found';
			return FALSE;
		}
	}
	
	
	
	
	function get_group_name($group_id){
		if($group_id == NULL || !is_numeric($group_id)){
			$this->lasterr = 'No group_id given or invalid data type.';
			return FALSE;
		}
		
		$sql = 'SELECT name FROM groups WHERE group_id = ? LIMIT 1';
		$query = $this->db->query($sql, array($group_id));
		
		if($query->num_rows() == 1){
			$row = $query->row();
			return $row->name;
		} else {
			$this->lasterr = sprintf('The group supplied (ID: %d) does not exist.', $group_id);
			return FALSE;
		}
	}
	
	
	
	
	
	function get_group_permissions($group_id = NULL){
	
		#echo $group_id;
		#die();
		
		if($group_id === NULL){
			
			// Getting permissions for all groups
			$sql = 'SELECT group_id, permissions FROM groups ORDER BY group_id ASC';
			$query = $this->db->query($sql);
			if($query->num_rows() > 0){
				$result = $query->result();
				$permissions = array();
				foreach($result as $row){
					$permissions[$row->group_id] = unserialize($row->permissions);
				}
				return $permissions;
			} else {
				$lasterr = 'No groups to get permissions from.';
				return FALSE;
			}
			
		} else {
			
			// Getting permissions from one group
			$sql = 'SELECT permissions FROM groups WHERE group_id=?';
			$query = $this->db->query($sql, array($group_id));
			if($query->num_rows() == 1){
				$row = $query->row();
				return unserialize($row->permissions);
			} else {
				$this->lasterr = 'No group to get permissions from.';
				return FALSE;
			}
			
		}
		
	}
	
	
	
	
	function save_group_permissions($group_id, $permissions){
		if($group_id === NULL || $group_id === FALSE || !is_numeric($group_id)){
			$this->lasterr = "Group ID ($group_id) was not valid.";
			return FALSE;
		}
		
		if(!is_array($permissions) || ($permissions != NULL)){
			$this->lasterr = 'Permissions was not supplied in valid format.';
			return FALSE;
		}
		
		$sql = 'UPDATE groups SET permissions = ? WHERE group_id = ? LIMIT 1';
		$query = $this->db->query($sql, array(serialize($permissions), $group_id));
		
		return $query;
	}
	
	
	
	
	
	function get_ldap_groups(){
		$existing_groups = array();
		$sql = 'SELECT * FROM ldapgroups ORDER BY name ASC';
		$query = $this->db->query($sql);
		if($query->num_rows() > 0){
			$results = $query->result();
			foreach($results as $result){
				$existing_groups[$result->ldapgroup_id] = $result->name;
			}
		}
		return $existing_groups;
	}
	
	
	
	
	/**
	 * Function to get LDAP groups _that have not already been set to map to CRBS groups_
	 * Specify a group ID to exlude it from the list:
	 * 		- Useful on edit page to still show which LDAP groups are assigned, but not ones assigned to *other* groups
	 */
	function get_ldap_groups_unassigned($group_id = NULL){
		$existing_groups = array();
		$sql = 'SELECT * 
				FROM ldapgroups 
				WHERE ldapgroup_id NOT IN (
					SELECT ldapgroup_id 
					FROM groups2ldapgroups
					%s
				) ORDER BY name ASC';
		// Is group_id specified? If so, we need to include these group mappings
		if($group_id !== NULL && is_numeric($group_id)){
			$sql2 = 'WHERE group_id != ?';
			$sql = sprintf($sql, $sql2);
			$query = $this->db->query($sql, array($group_id));
		} else {
			$sql = sprintf($sql, '');
			$query = $this->db->query($sql);
		}
		if($query->num_rows() > 0){
			$results = $query->result();
			foreach($results as $result){
				$existing_groups[$result->ldapgroup_id] = $result->name;
			}
		}
		return $existing_groups;
	}
	
	
	
	
	/**
	 * Transform an LDAP group name into a local CRBS group_id
	 *
	 * Used for finding out which group a new LDAP user should be assigned to.
	 * Use no parameters to get an array of ldapgroupname => local group id
	 *
	 * @param	string	ldapgroupname		Name of group to attempt to map to local group ID
	 * @return	mixed						Array of groups=>ids, Local group ID, or FALSE
	 */
	function ldap_groupname_to_group($ldapgroupname = NULL){
		
		if($ldapgroupname == NULL){
			
			$sql = 'SELECT g2l.group_id, lg.name
					FROM groups2ldapgroups AS g2l
					LEFT JOIN ldapgroups AS lg ON g2l.ldapgroup_id = lg.ldapgroup_id';
			$query = $this->db->query($sql);
			
			if($query->num_rows() > 0){
				$result = $query->result();
				foreach($result as $row){
					$groups[$row->name] = $row->group_id;
				}
				return $groups;
			} else {
				$this->lasterr = 'No LDAP group mappings found.';
				return FALSE;
			}
			
		} else {
			
			$sql = 'SELECT group_id
					FROM groups2ldapgroups AS g2l
					LEFT JOIN ldapgroups ON g2l.ldapgroup_id = ldapgroups.ldapgroup_id
					WHERE ldapgroups.name = ?
					LIMIT 1';
			$query = $this->db->query($sql, array($ldapgroupname));
			#echo $this->db->last_query() . "\n\n\n";
			if($query->num_rows() == 1){
				$row = $query->row();
				$group_id = $row->group_id;
				return $group_id;
			} else {
				$this->lasterr = 'No groups found';
				return FALSE;
			}
			
		}
	}
	
	
	
	
	/**
	 * Look up an array of LDAP group names and find which deparments are mapped to them
	 *
	 * @param array ldap group names
	 * @return array Department IDs
	 */
	function ldap_groupnames_to_departments($ldapgroupnames){
		
		// Create initial array of department IDs
		$departments = array();
		
		// Main SQL query string
		$sql = 'SELECT department_id
				FROM departments2ldapgroups AS d2l
				LEFT JOIN ldapgroups ON d2l.ldapgroup_id = ldapgroups.ldapgroup_id
				WHERE ldapgroups.name = ?';
		
		// Loop all group names in the supplied array
		foreach($ldapgroupnames as $ldapgroupname){
			// Run query with this group name
			$query = $this->db->query($sql, array($ldapgroupname));
			if($query->num_rows() > 0){
				// Got some results, add the department_id(s) to the array
				$result = $query->result();
				foreach($result as $row){
					//$deparments[] = $row->department_id;
					array_push($departments, $row->department_id);
				}
			}
		}
		
		// Check if any departments were found
		if(count($departments) > 0){
			return $departments;
		} else {
			$this->lasterr = 'No departments are associated with the supplied LDAP groups.';
			return $departments;
		}
		
	}
	
	
	
	
	function get_user_permissions($user_id){
		if(!is_numeric($user_id)){
			$this->lasterr = 'User ID supplied was invalid.';
			return FALSE;
		}
		
		$sql = 'SELECT permissions 
				FROM groups 
				LEFT JOIN users ON groups.group_id = users.group_id
				WHERE users.user_id = ?
				LIMIT 1';
		$query = $this->db->query($sql, array($user_id));
		
		if($query->num_rows() == 1){
			$row = $query->row();
			$group_permissions = unserialize($row->permissions);
			// Check if there are actually any permissions configured for the group
			if(!is_array($group_permissions)){
				$this->lasterr = 'No permissions configured for the group.';
				return FALSE;
			}
			//return $permissions;
			$all_permissions = $this->config->item('permissions');
			#print_r($all_permissions);
			$effective = array();
			foreach($all_permissions as $category){
				foreach($category as $items){
					#print_r($items);
					foreach($group_permissions as $p){
						#echo $items[0] . "\n\n";
						#echo $p . "\n\n";
						if($items[0] == $p){
							#echo var_export($items[0], TRUE) . "\n\n\n";
							$effective[] = $items;
						}
					}
				}	
			}
			
			#print_r($effective);
			return $effective;
		} else {
			$this->lasterr = 'Could not find permissions';
			return FALSE;
		}
	}
	
	
	
	
}




/* End of file: app/models/security_model.php */