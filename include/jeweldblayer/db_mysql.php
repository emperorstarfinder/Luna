<?php

/*
 * Copyright (c) 2013-2015 Luna
 * License under MIT
 */

// Do we have support for MySQL?
if (!function_exists('mysql_connect'))
	exit('Your host does not support MySQL. MySQL support is required to use a MySQL database to run Luna.');

class DBConnect {
	var $datatype_transforms = array(
		'%^SERIAL$%'	=>	'INT(10) UNSIGNED AUTO_INCREMENT'
	);

	var $error_message = 'Unknown';
	var $error_no = false;
	var $link_id;
	var $num_queries = 0;
	var $prefix;
	var $query_result;
	var $stored_queries = array();

	function DBConnect($db_host, $db_username, $db_password, $db_name, $db_prefix, $p_connect) {
		$this->prefix = $db_prefix;

		if ($p_connect)
			$this->link_id = @mysql_pconnect($db_host, $db_username, $db_password);
		else
			$this->link_id = @mysql_connect($db_host, $db_username, $db_password);

		if ($this->link_id) {
			if (!@mysql_select_db($db_name, $this->link_id))
				error('Unable to select database. MySQL reported: '.mysql_error(), __FILE__, __LINE__);
		} else
			error('Unable to connect to MySQL server. MySQL reported: '.mysql_error(), __FILE__, __LINE__);

		// Setup the client-server character set (UTF-8)
		if (!defined('FORUM_NO_SET_NAMES'))
			$this->set_names('utf8');

		return $this->link_id;
	}

	function start_connection() {
		return;
	}

	function end_connection() {
		return;
	}

	function query($sql, $unbuffered = false) {
		if (defined('FORUM_SHOW_QUERIES'))
			$q_start = get_microtime();

		if ($unbuffered)
			$this->query_result = @mysql_unbuffered_query($sql, $this->link_id);
		else
			$this->query_result = @mysql_query($sql, $this->link_id);

		if ($this->query_result) {
			if (defined('FORUM_SHOW_QUERIES'))
				$this->stored_queries[] = array($sql, sprintf('%.5f', get_microtime() - $q_start));

			++$this->num_queries;

			return $this->query_result;
		} else {
			if (defined('FORUM_SHOW_QUERIES'))
				$this->stored_queries[] = array($sql, 0);

			$this->error_no = @mysql_errno($this->link_id);
			$this->error_message = @mysql_error($this->link_id);

			return false;
		}
	}

	function result($query_id = 0, $row = 0, $col = 0) {
		return ($query_id) ? @mysql_result($query_id, $row, $col) : false;
	}

	function fetch_assoc($query_id = 0) {
		return ($query_id) ? @mysql_fetch_assoc($query_id) : false;
	}

	function fetch_row($query_id = 0) {
		return ($query_id) ? @mysql_fetch_row($query_id) : false;
	}

	function num_rows($query_id = 0) {
		return ($query_id) ? @mysql_num_rows($query_id) : false;
	}

	function affected_rows() {
		return ($this->link_id) ? @mysql_affected_rows($this->link_id) : false;
	}

	function insert_id() {
		return ($this->link_id) ? @mysql_insert_id($this->link_id) : false;
	}

	function get_num_queries() {
		return $this->num_queries;
	}

	function get_stored_queries() {
		return $this->stored_queries;
	}

	function free_result($query_id = false) {
		return ($query_id) ? @mysql_free_result($query_id) : false;
	}

	function escape($string) {
		if (is_array($string))
			return '';
		elseif (function_exists('mysql_real_escape_string'))
			return mysql_real_escape_string($string, $this->link_id);
		else
			return mysql_escape_string($string);
	}

	function error() {
		$result['error_sql'] = @current(@end($this->stored_queries));
		$result['error_no'] = $this->error_no;
		$result['error_message'] = $this->error_message;

		return $result;
	}

	function close() {
		if ($this->link_id) {
			if ($this->query_result)
				@mysql_free_result($this->query_result);

			return @mysql_close($this->link_id);
		} else
			return false;
	}

	function get_names() {
		$result = $this->query('SHOW VARIABLES LIKE \'character_set_connection\'');
		return $this->result($result, 0, 1);
	}

	function set_names($names) {
		return $this->query('SET NAMES \''.$this->escape($names).'\'');
	}

	function get_version() {
		$result = $this->query('SELECT VERSION()');

		return array(
			'name'		=> 'MySQL Standard',
			'version'	=> preg_replace('%^([^-]+).*$%', '\\1', $this->result($result))
		);
	}

	function exists_table($table_name) {
		$result = $this->query('SHOW TABLES LIKE \''.$this->prefix.$this->escape($table_name).'\'');
		return $this->num_rows($result) > 0;
	}

	function exists_field($table_name, $field_name) {
		$result = $this->query('SHOW COLUMNS FROM '.$this->prefix.$table_name.' LIKE \''.$this->escape($field_name).'\'');
		return $this->num_rows($result) > 0;
	}

	function exists_index($table_name, $index_name) {
		$exists = false;

		$result = $this->query('SHOW INDEX FROM '.$this->prefix.$table_name);
		while ($cur_index = $this->fetch_assoc($result)) {
			if (strtolower($cur_index['Key_name']) == strtolower($this->prefix.$table_name.'_'.$index_name)) {
				$exists = true;
				break;
			}
		}

		return $exists;
	}

	function add_table($table_name, $schema) {
		if ($this->exists_table($table_name))
			return true;

		$query = 'CREATE TABLE '.$this->prefix.$table_name." (\n";

		// Go through every schema element and add it to the query
		foreach ($schema['FIELDS'] as $field_name => $field_data) {
			$field_data['datatype'] = preg_replace(array_keys($this->datatype_transforms), array_values($this->datatype_transforms), $field_data['datatype']);

			$query .= $field_name.' '.$field_data['datatype'];

			if (isset($field_data['collation']))
				$query .= 'CHARACTER SET utf8 COLLATE utf8_'.$field_data['collation'];

			if (!$field_data['allow_null'])
				$query .= ' NOT NULL';

			if (isset($field_data['default']))
				$query .= ' DEFAULT '.$field_data['default'];

			$query .= ",\n";
		}

		// If we have a primary key, add it
		if (isset($schema['PRIMARY KEY']))
			$query .= 'PRIMARY KEY ('.implode(',', $schema['PRIMARY KEY']).'),'."\n";

		// Add unique keys
		if (isset($schema['UNIQUE KEYS'])) {
			foreach ($schema['UNIQUE KEYS'] as $key_name => $key_fields)
				$query .= 'UNIQUE KEY '.$this->prefix.$table_name.'_'.$key_name.'('.implode(',', $key_fields).'),'."\n";
		}

		// Add indexes
		if (isset($schema['INDEXES'])) {
			foreach ($schema['INDEXES'] as $index_name => $index_fields)
				$query .= 'KEY '.$this->prefix.$table_name.'_'.$index_name.'('.implode(',', $index_fields).'),'."\n";
		}

		// We remove the last two characters (a newline and a comma) and add on the ending
		$query = substr($query, 0, strlen($query) - 2)."\n".') ENGINE = '.(isset($schema['ENGINE']) ? $schema['ENGINE'] : 'MyISAM').' CHARACTER SET utf8';

		return $this->query($query) ? true : false;
	}

	function delete_table($table_name) {
		if (!$this->exists_table($table_name))
			return true;

		return $this->query('DROP TABLE '.$this->prefix.$table_name) ? true : false;
	}

	function rename_table($old_table, $added_table) {
		// If the new table exists and the old one doesn't, then we're happy
		if ($this->exists_table($added_table) && !$this->exists_table($old_table))
			return true;

		return $this->query('ALTER TABLE '.$this->prefix.$old_table.' RENAME TO '.$this->prefix.$added_table) ? true : false;
	}

	function add_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null) {
		if ($this->exists_field($table_name, $field_name))
			return true;

		$field_type = preg_replace(array_keys($this->datatype_transforms), array_values($this->datatype_transforms), $field_type);

		if (!is_null($default_value) && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		return $this->query('ALTER TABLE '.$this->prefix.$table_name.' ADD '.$field_name.' '.$field_type.($allow_null ? '' : ' NOT NULL').(!is_null($default_value) ? ' DEFAULT '.$default_value : '').(!is_null($after_field) ? ' AFTER '.$after_field : '')) ? true : false;
	}

	function change_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null) {
		if (!$this->exists_field($table_name, $field_name))
			return true;

		$field_type = preg_replace(array_keys($this->datatype_transforms), array_values($this->datatype_transforms), $field_type);

		if (!is_null($default_value) && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		return $this->query('ALTER TABLE '.$this->prefix.$table_name.' MODIFY '.$field_name.' '.$field_type.($allow_null ? '' : ' NOT NULL').(!is_null($default_value) ? ' DEFAULT '.$default_value : '').(!is_null($after_field) ? ' AFTER '.$after_field : '')) ? true : false;
	}

	function delete_field($table_name, $field_name) {
		if (!$this->exists_field($table_name, $field_name))
			return true;

		return $this->query('ALTER TABLE '.$this->prefix.$table_name.' DROP '.$field_name) ? true : false;
	}

	function add_index($table_name, $index_name, $index_fields, $unique = false) {
		if ($this->exists_index($table_name, $index_name))
			return true;

		return $this->query('ALTER TABLE '.$this->prefix.$table_name.' ADD '.($unique ? 'UNIQUE ' : '').'INDEX '.$this->prefix.$table_name.'_'.$index_name.' ('.implode(',', $index_fields).')') ? true : false;
	}

	function delete_index($table_name, $index_name) {
		if (!$this->exists_index($table_name, $index_name))
			return true;

		return $this->query('ALTER TABLE '.$this->prefix.$table_name.' DROP INDEX '.$this->prefix.$table_name.'_'.$index_name) ? true : false;
	}

	function add_config($config_name, $config_value) {
		if (!array_key_exists($config_name, $luna_config))
			return $this->query('INSERT INTO '.$this->prefix.'config (conf_name, conf_value) VALUES (\''.$config_name.'\', \''.$config_value.'\')') or error('Unable to insert config value \''.$config_name.'\'', __FILE__, __LINE__, $db->error());
	}

	function delete_config($config_name) {
		if (!array_key_exists($config_name, $luna_config))
			return $this->query('DELETE FROM '.$this->prefix.'config WHERE conf_name = \''.$config_name.'\'') or error('Unable to remove config value \''.$config_name.'\'', __FILE__, __LINE__, $db->error());
	}

	function truncate_table($table_name) {
		return $this->query('TRUNCATE TABLE '.$this->prefix.$table_name) ? true : false;
	}
}