<?php
/**
 * Classes and functions for importing data to LDAP
 *
 * These classes provide differnet import formats.
 *
 * @author The phpLDAPadmin development team
 * @package phpLDAPadmin
 * @see import.php and import_form.php
 */

/**
 * Importer Class
 *
 * This class serves as a top level importer class, which will return
 * the correct Import class.
 *
 * @package phpLDAPadmin
 * @subpackage Import
 */
class Importer {
	# Server ID that the export is linked to
	private $server_id;
	# Import Type
	private $template_id;
	private $template;

	public function __construct($server_id,$template_id) {
		$this->server_id = $server_id;
		$this->template_id = $template_id;

		$this->accept();
	}

	static function types() {
		$type = array();

		$details = ImportLDIF::getType();
		$type[$details['type']] = $details;

		return $type;
	}

	private function accept() {
		switch($this->template_id) {
			case 'LDIF':
				$this->template = new ImportLDIF($this->server_id);
				break;

			default:
				system_message(array(
					'title'=>sprintf('%s %s',_('Unknown Import Type'),$this->template_id),
					'body'=>_('phpLDAPadmin has not been configured for that import type'),
					'type'=>'warn'),'index.php');

				die();
		}

		$this->template->accept();
	}

	public function getTemplate() {
		return $this->template;
	}
}

/**
 * Import Class
 *
 * This abstract classes provides all the common methods and variables for the
 * custom import classes.
 *
 * @package phpLDAPadmin
 * @subpackage Import
 */
abstract class Import {
	protected $server_id = null;
	protected $input = null;
	protected $source = array();

	public function __construct($server_id) {
		$this->server_id = $server_id;
	}

	public function accept() {
		if (get_request('ldif','REQUEST')) {
			$this->input = preg_split("/\n|\r\n|\r/",get_request('ldif','REQUEST'));
			$this->source['name'] = 'STDIN';
			$this->source['size'] = strlen(get_request('ldif','REQUEST'));

		} elseif (isset($_FILES['ldif_file']) && is_array($_FILES['ldif_file']) && ! $_FILES['ldif_file']['error']) {
			$input = file_get_contents($_FILES['ldif_file']['tmp_name']);
			$this->input = preg_split("/\n|\r\n|\r/",$input);
			$this->source['name'] = $_FILES['ldif_file']['name'];
			$this->source['size'] = $_FILES['ldif_file']['size'];

		} else {
			system_message(array(
				'title'=>_('No import input'),
				'body'=>_('You must either upload a file or provide an import in the text box.'),
				'type'=>'error'),sprintf('cmd.php?cmd=import_form&server_id=%s',get_request('server_id','REQUEST')));

			die();
		}
	}

	public function getSource($attr) {
		if (isset($this->source[$attr]))
			return $this->source[$attr];
		else
			return null;
	}

	# @todo integrate hooks
	public function LDAPimport() {
		$template = $this->getTemplate();
		$server = $this->getServer();

		switch ($template->getType()) {
			case 'add': 
				return $server->add($template->getDN(),$template->getLDAPadd());

			case 'modify':
				return $server->modify($template->getDN(),$template->getLDAPmodify());

			case 'moddn':
			case 'modrdn':
				return $server->rename($template->getDN(),$template->modrdn['newrdn'],$template->modrdn['newsuperior'],$template->modrdn['deleteoldrdn']);

			default:
				debug_dump_backtrace(sprintf('Unknown template type %s',$template->getType()),1);
		}

		return true;
	}
}

/**
 * Import entries from LDIF
 *
 * The LDIF spec is described by RFC2849
 * http://www.ietf.org/rfc/rfc2849.txt
 *
 * @package phpLDAPadmin
 * @subpackage Import
 */
class ImportLDIF extends Import {
	private $_currentLineNumber = 0;
	private $_currentLine = '';
	private $template;
	public $error = array();

	static public function getType() {
		return array('type'=>'LDIF','description' => _('LDIF Import'),'extension'=>'ldif');
	}

	protected function getTemplate() {
		return $this->template;
	}

	protected function getServer() {
		return $_SESSION[APPCONFIG]->getServer($this->server_id);
	}

	public function readEntry() {
		static $haveVersion = false;

		if ($lines = $this->nextLines()) {

			# If we have a version line.
			if (! $haveVersion && preg_match('/^version:/',$lines[0])) {
				list($text,$version) = $this->getAttrValue(array_shift($lines), false);

				if ($version != 1)
					return $this->error(sprintf('%s %s',_('LDIF import only supports version 1'),$version),$lines);

				$haveVersion = true;
				if (!$lines)
					$lines = $this->nextLines();
			}

			$server = $this->getServer();

			# The first line should be the DN
			if (preg_match('/^dn:/',$lines[0])) {
				list($text,$dn) = $this->getAttrValue(array_shift($lines));

				# The second line should be our changetype
				if (preg_match('/^changetype:[ ]*(delete|add|modrdn|moddn|modify)/i',$lines[0])) {
					$attrvalue = $this->getAttrValue($lines[0], false);
					$changetype = $attrvalue[1];
					array_shift($lines);

				} else
					$changetype = 'add';

				$this->template = new Template($this->server_id,null,null,$changetype);

				switch ($changetype) {
					case 'add':
						$rdn = get_rdn($dn);
						$container = $server->getContainer($dn);

						$this->template->setContainer($container);
						$this->template->accept();

						$this->getAddDetails($lines);
						$this->template->setRDNAttributes($rdn);

						return $this->template;

						break;

					case 'modify':
						if (! $server->dnExists($dn))
							return $this->error(sprintf('%s %s',_('DN does not exist'),$dn),$lines);

						$this->template->setDN($dn);
						$this->template->accept(false,true);

						return $this->getModifyDetails($lines);

						break;

					case 'moddn':
					case 'modrdn':
						if (! $server->dnExists($dn))
							return $this->error(sprintf('%s %s',_('DN does not exist'),$dn),$lines);

						$this->template->setDN($dn);
						$this->template->accept();

						return $this->getModRDNAttributes($lines);

						break;

					default:
						if (! $server->dnExists($dn))
							return $this->error(_('Unkown change type'),$lines);
				}

			} else
				return $this->error(_('A valid dn line is required'),$lines);

		} else
			return false;
	}

	/**
	 * Get the Attribute and Decoded Value
	 */
	private function getAttrValue($line, $useBase64 = true, $useUrl = false) {
		$m = explode(':',$line,2);

		if (!isset($m[1])) {
			$this->error(_('Invalid Attribute definition'),$line);
			return array($line, false);
		}

		list($attr,$value) = $m;

		# Translate the value.
		if ($useBase64 && substr($value,0,1) == ':')
			$value = base64_decode(trim(substr($value,1)));
		else if ($useUrl && substr($value,0,1) == '<') {
			$url = trim(substr($value,1));

			/* WARNING/TODO: URL importation is unsafe because it may reveal ANY readable server file content */
			if (!defined('PLA_UNSAFE'))
				$value = $this->error(_('LDIF URL importation is not supported'),$value);
			else if (!preg_match('#^file://#',$url))
				$value = $this->error(_('The url attribute value should begin with file:// for'),$value);
			else if (($sb = stat($filename = substr($url,7))) && isset($sb['mode']) && ($sb['mode'] & 0170000) != 0100000) {
				/* If the file exists, it must be a (link to a) regular file. */
				$value = $this->error(_('URL is not a regular file'),$value);
			} else {
				if (!($fh = @fopen($filename,'rb')))
					$this->error(_('Unable to open file for'),$value);
				else {
					if (($data = @fread($fh,filesize($filename))) === false)
						$this->error(_('Unable to read file for '),$value);
					else
						$value = $data;

					@fclose($fh);
				}
			}

		# It's a string
		} else
			$value = ltrim($value);

		return array($attr,$value);
	}

	/**
	 * Get the lines of the next entry
	 *
	 * @return The lines (unfolded) of the next entry
	 */
	private function nextLines() {
		$current = array();
		$endEntryFound = false;

		if ($this->hasMoreEntries() && ! $this->eof()) {
			# The first line is the DN one
			$current[0]= $this->_currentLine;

			# While we end on a blank line, fetch the attribute lines
			$count = 0;
			while (! $this->eof() && ! $endEntryFound) {
				# Fetch the next line
				$this->nextLine();

				if ($this->isCommentLine()) {}
				# Do nothing
				elseif (! $this->isBlankLine())
					$current[++$count] = $this->_currentLine;
				else
					$endEntryFound = true;
			}

			# Return the LDIF entry array
			return $current;

		} else
			return array();
	}

	/**
	 * Private method to check if there is more entries in the input.
	 *
	 * @return boolean true if an entry was found, false otherwise.
	 */
	private function hasMoreEntries() {
		$entry_found = false;

		while (! $this->eof() && ! $entry_found) {
			# If it's a comment or blank line, switch to the next line
			if ($this->isCommentLine() || $this->isBlankLine()) {
				# Do nothing
				$this->nextLine();

			} else
				$entry_found = true;
		}

		return $entry_found;
	}

	/**
	 * Helper method to switch to the next line
	 */
	private function nextLine() {
		$this->_currentLineNumber++;
		$this->_currentLine = array_shift($this->input);

		// Unfold here: comments may be folded too (RFC2849, note 2, page 6).
		while ($this->isWrappedLine()) {
			$this->_currentLineNumber++;
			$this->_currentLine .= substr(array_shift($this->input), 1);
		}
	}

	/**
	 * Check if it's a comment line.
	 *
	 * @return boolean true if it's a comment line,false otherwise
	 */
	private function isCommentLine() {
		return substr(trim($this->_currentLine),0,1) == '#' ? true : false;
	}

	/**
	 * Check if it's a wrapped line.
	 *
	 * @return boolean true if it's a wrapped line,false otherwise
	 */
	private function isWrappedLine() {
		return $this->_currentLine != '' && !$this->eof() && substr($this->input[0], 0, 1) == ' ';
	}

	/**
	 * Check if is the current line is a blank line.
	 *
	 * @return boolean if it is a blank line,false otherwise.
	 */
	private function isBlankLine() {
		return(trim($this->_currentLine) == '') ? true : false;
	}

	/**
	 * Returns true if we reached the end of the input.
	 *
	 * @return boolean true if it's the end of file, false otherwise.
	 */
	public function eof() {
		return count($this->input) > 0 ? false : true;
	}

	private function error($msg,$data) {
		$this->error['message'] = sprintf('%s [%s]',$msg,$this->template ? $this->template->getDN() : '');
		$this->error['line'] = $this->_currentLineNumber;
		$this->error['data'] = $data;
		$this->error['changetype'] = $this->template ? $this->template->getType() : 'Not set';

		return false;
	}

	/**
	 * Build the attributes array when the change type is add.
	 */
	private function getAddDetails($lines) {
		foreach ($lines as $line) {
			list($attr,$value) = $this->getAttrValue($line, true, true);

			if ($value !== false) {
				if (preg_match('/^(.+);binary(;.*|)$/i', $attr, $m))
					$attr = $m[1] . $m[2];

				if (is_null($attribute = $this->template->getAttribute($attr))) {
					$attribute = $this->template->addAttribute($attr,array('values'=>array($value)));
					$attribute->justModified();

				} else
					if ($attribute->hasBeenModified())
						$attribute->addValue($value);
					else
						$attribute->setValue(array($value));
			}
		}
	}

	/**
	 * Build the attributes array for the entry when the change type is modify
	 */
	private function getModifyDetails($lines) {
		if (! count($lines))
			return $this->error(_('Missing attributes for'),$lines);

		# While the array is not empty
		while (count($lines)) {
			$processline = false;
			$deleteattr = false;

			# Get the current line with the action
			$currentLine = array_shift($lines);
			$attrvalue = $this->getAttrValue($currentLine, false);
			$action_attribute = $attrvalue[0];
			$action_attribute_value = $attrvalue[1];

			if ($action_attribute_value === false)
				return false;

			if (! in_array($action_attribute,array('add','delete','replace')))
				return $this->error(_('Missing modify command add, delete or replace'),array_merge(array($currentLine),$lines));

			if (preg_match('/^(.+);binary(;.*|)$/i', $action_attribute_value, $m))
				$action_attribute_value = $m[1] . $m[2];

			$processline = true;
			switch ($action_attribute) {
				case 'add':

					break;

				case 'delete':
					$attribute = $this->template->getAttribute($action_attribute_value);

					if (is_null($attribute))
						return $this->error(sprintf('%s %s',_('Attempting to delete a non existant attribute'),$action_attribute_value),
							array_merge(array($currentLine),$lines));

					$deleteattr = true;

					break;

				case 'replace':
					$attribute = $this->template->getAttribute($action_attribute_value);

					if (is_null($attribute))
						return $this->error(sprintf('%s %s',_('Attempting to replace a non existant attribute'),$action_attribute_value),
							array_merge(array($currentLine),$lines));

					break;

				default:
					debug_dump_backtrace(sprintf('Unknown action %s',$action_attribute),1);
			}

			# Fetch the attribute for the following line
			$currentLine = array_shift($lines);

			while ($processline && trim($currentLine) && (trim($currentLine) != '-')) {
				$processline = false;

				# If there is a valid line
				if (preg_match('/:/',$currentLine)) {
					$attrvalue = $this->getAttrValue($currentLine, true, true);
					$attr = $attrvalue[0];
					$attribute_value_part = $attrvalue[1];

					if ($attribute_value_part === false)
						return false;

					if (preg_match('/^(.+);binary(;.*|)$/i', $attr, $m))
						$attr = $m[1] . $m[2];

					# Check that it correspond to the one specified before
					if ($attr == $action_attribute_value) {
						# Get the value part of the attribute
						$attribute = $this->template->getAttribute($attr);

						# This should be a add/replace operation
						switch ($action_attribute) {
							case 'add':
								if (is_null($attribute))
									$attribute = $this->template->addAttribute($attr,array('values'=>array($attribute_value_part)));
								else
									$attribute->addValue($attribute_value_part,-1);

								$attribute->justModified();

								break;

							case 'delete':
								$deleteattr = false;

								if (($key = array_search($attribute_value_part,$attribute->getValues())) !== false)
									$attribute->delValue($key);
								else
									return $this->error(sprintf('%s %s',_('Delete value doesnt exist in DN'),$attribute_value_part),
										array_merge(array($currentLine),$lines));


								break;

							case 'replace':
								if ($attribute->hasBeenModified())
									$attribute->addValue($attribute_value_part,-1);
								else
									$attribute->setValue(array($attribute_value_part));

								break;

							default:
								debug_dump_backtrace(sprintf('Unexpected operation %s',$action_attribute));
						}

					} else
						return $this->error(sprintf('%s %s',_('The attribute to modify doesnt match the one specified by'),$action_attribute),
							array_merge(array($currentLine),$lines));

				} else
					return $this->error(sprintf('%s %s',_('Attribute not valid'),$currentLine),
						array_merge(array($currentLine),$lines));

				$currentLine = array_shift($lines);
				if (trim($currentLine))
					$processline = true;
			}

			if ($action_attribute == 'delete' && $deleteattr)
				$attribute->setValue(array());

		}

		return $this->template;
	}

	/**
	 * Build the attributes for the entry when the change type is modrdn
	 */
	function getModRDNAttributes($lines) {
		$server = $this->getServer();
		$attrs = array();

		# MODRDN MODDN should only be 2 or 3 lines.
		if (count($lines) != 2 && count($lines) !=3)
			return $this->error(_('Invalid entry'),$lines);

		else {
			$currentLine = array_shift($lines);

			# First we need to check if there is an new rdn specified
			if (preg_match('/^newrdn:(:?)/',$currentLine)) {

				$attrvalue = $this->getAttrValue($currentLine);

				if ($attrvalue[1] === false)
					return false;

				$attrs['newrdn'] = $attrvalue[1];

				$currentLine = array_shift($lines);

				if (preg_match('/^deleteoldrdn:[ ]*(0|1)/',$currentLine)) {
					$attrvalue = $this->getAttrValue($currentLine);

					if ($attrvalue[1] === false)
						return false;

					$attrs['deleteoldrdn'] = $attrvalue[1];

					# Switch to the possible new superior attribute
					if (count($lines)) {
						$currentLine = array_shift($lines);

						# then the possible new superior attribute
						if (preg_match('/^newsuperior:/',$currentLine)) {
							$attrvalue = $this->getAttrValue($currentLine);

							if ($attrvalue[1] === false)
								return false;

							$attrs['newsuperior'] = $attrvalue[1];

						} else
							return $this->error(_('A valid newsuperior attribute should be specified'),$lines);

					} else
						$attrs['newsuperior'] = $server->getContainer($this->template->getDN());

				} else
					return $this->error(_('A valid deleteoldrdn attribute should be specified'),$lines);

			} else
				return $this->error(_('A valid newrdn attribute should be specified'),$lines);
		}

		# Well do something out of the ordinary here, since our template doesnt handle mod[r]dn yet.
		$this->template->modrdn = $attrs;
		return $this->template;
	}
}
?>
