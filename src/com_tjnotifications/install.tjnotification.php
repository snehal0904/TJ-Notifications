<?php
/**
 * @package    Com_Tjnotification
 * @copyright  Copyright (C) 2005 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die;
jimport('joomla.installer.installer');
jimport('joomla.filesystem.file');

/**
 * Script file of TJNotification component
 *
 * @since  1.0.0
 **/
class Com_TjnotificationsInstallerScript
{
	/** @var array The list of extra modules and plugins to install */
	private $queue = array(
		// Plugins => { (folder) => { (element) => (published) }* }*
		'plugins' => array(
			'actionlog' => array(
				'tjnotification' => 1
			),
			'privacy' => array(
				'tjnotification' => 1,
			),
		),
	);

	/**
	 * method to install the component
	 *
	 * @param   JInstaller  $parent  parent
	 *
	 * @return void
	 */
	public function install($parent)
	{
	}

	/**
 	* This method is called after a component is uninstalled.
 	*
 	* @param   \stdClass  $parent  Parent object calling this method.
 	*
 	* @return void
 	*/
	public function uninstall($parent)
	{
		jimport('joomla.installer.installer');
		$db              = JFactory::getDBO();
		$status          = new JObject;
		$status->plugins = array();
		$src             = $parent->getParent()->getPath('source');

		// Plugins uninstallation
		if (count($this->queue['plugins']))
		{
			foreach ($this->queue['plugins'] as $folder => $plugins)
			{
				if (count($plugins))
				{
					foreach ($plugins as $plugin => $published)
					{
						$sql = $db->getQuery(true)->select($db->qn('extension_id'))
						->from($db->qn('#__extensions'))
						->where($db->qn('type') . ' = ' . $db->q('plugin'))
						->where($db->qn('element') . ' = ' . $db->q($plugin))
						->where($db->qn('folder') . ' = ' . $db->q($folder));
						$db->setQuery($sql);

						$id = $db->loadResult();

						if ($id)
						{
							$installer         = new JInstaller;
							$result            = $installer->uninstall('plugin', $id);
							$status->plugins[] = array(
								'name' => 'plg_' . $plugin,
								'group' => $folder,
								'result' => $result
							);
						}
					}
				}
			}
		}

		return $status;
	}

	/**
	 * method to update the component
	 *
	 * @param   JInstaller  $parent  parent
	 *
	 * @return void
	 */
	public function update($parent)
	{
		// Install SQL FIles
		$this->installSqlFiles($parent);
		$this->fix_db_on_update();
	}

	/**
	 * method to run before an install/update/uninstall method
	 *
	 * @param   JInstaller  $type    type
	 * @param   JInstaller  $parent  parent
	 *
	 * @return void
	 */
	public function preflight($type, $parent)
	{
	}

	/**
	 * method to run after an install/update/uninstall method
	 *
	 * @param   JInstaller  $type    type
	 * @param   JInstaller  $parent  parent
	 *
	 * @return void
	 */
	public function postflight($type, $parent)
	{
		$src             = $parent->getParent()->getPath('source');
		$db              = JFactory::getDbo();
		$status          = new JObject;
		$status->plugins = array();

		// Plugins installation
		if (count($this->queue['plugins']))
		{
			foreach ($this->queue['plugins'] as $folder => $plugins)
			{
				if (count($plugins))
				{
					foreach ($plugins as $plugin => $published)
					{
						$path = "$src/plugins/$folder/$plugin";

						if (!is_dir($path))
						{
							$path = "$src/plugins/$folder/plg_$plugin";
						}

						if (!is_dir($path))
						{
							$path = "$src/plugins/$plugin";
						}

						if (!is_dir($path))
						{
							$path = "$src/plugins/plg_$plugin";
						}

						if (!is_dir($path))
						{
							continue;
						}

						// Was the plugin already installed?
						$query = $db->getQuery(true)
							->select('COUNT(*)')
							->from($db->qn('#__extensions'))
							->where($db->qn('element') . ' = ' . $db->q($plugin))
							->where($db->qn('folder') . ' = ' . $db->q($folder));
						$db->setQuery($query);
						$count = $db->loadResult();

						$installer = new JInstaller;
						$result = $installer->install($path);

						$status->plugins[] = array('name' => 'plg_' . $plugin, 'group' => $folder, 'result' => $result);

						if ($published && !$count)
						{
							$query = $db->getQuery(true)
								->update($db->qn('#__extensions'))
								->set($db->qn('enabled') . ' = ' . $db->q('1'))
								->where($db->qn('element') . ' = ' . $db->q($plugin))
								->where($db->qn('folder') . ' = ' . $db->q($folder));
							$db->setQuery($query);
							$db->execute();
						}
					}
				}
			}
		}

		// Install SQL FIles
		$this->installSqlFiles($parent);
	}

	/**
	 * installSqlFiles
	 *
	 * @param   JInstaller  $parent  parent
	 *
	 * @return  void
	 */
	public function installSqlFiles($parent)
	{
		$db = JFactory::getDbo();

		// Obviously you may have to change the path and name if your installation SQL file
		if (method_exists($parent, 'extension_root'))
		{
			$sqlfile = $parent->getPath('extension_root') . '/admin/sql/install.mysql.utf8.sql';
		}
		else
		{
			$sqlfile = $parent->getParent()->getPath('extension_root') . '/sql/install.mysql.utf8.sql';
		}

		// Don't modify below this line
		$buffer = file_get_contents($sqlfile);

		if ($buffer !== false)
		{
			jimport('joomla.installer.helper');
			$queries = JInstallerHelper::splitSql($buffer);

			if (count($queries) != 0)
			{
				foreach ($queries as $query)
				{
					$query = trim($query);

					if (!empty($query))
					{
						$db->setQuery($query);

						if (!$db->execute())
						{
							JError::raiseWarning(1, JText::sprintf('JLIB_INSTALLER_ERROR_SQL_ERROR', $db->stderr(true)));

							return false;
						}
					}
				}
			}
		}

		$config = JFactory::getConfig();
		$configdb = $config->get('db');

		// Get dbprefix
		$dbprefix = $config->get('dbprefix');
	}

	/**
	 * Fix template table
	 *
	 * @param   int  $db        db.
	 * @param   int  $dbprefix  dbprefix
	 * @param   int  $config    config
	 *
	 * @return  void
	 *
	 * @Since tjnotification version 1.0
	 */
	public function fixTemplateTable($db, $dbprefix, $config)
	{
		$query = "SHOW COLUMNS FROM #__tj_notification_templates WHERE `Field` = 'title'";
		$db->setQuery($query);
		$check = $db->loadResult();

		if (!$check)
		{
			$query = " ALTER TABLE  `#__tj_notification_templates` ADD  `title` varchar(100)	AFTER  `is_override`";
			$db->setQuery($query);

			if (!$db->execute())
			{
				JError::raiseError(500, $db->stderr());
			}
		}

		$query = "SHOW COLUMNS FROM #__tj_notification_templates WHERE `Field` = 'user_control'";
		$db->setQuery($query);
		$check = $db->loadResult();

		if (!$check)
		{
			$query = " ALTER TABLE  `#__tj_notification_templates` ADD  `user_control` int(1)	AFTER  `is_override`";
			$db->setQuery($query);

			if (!$db->execute())
			{
				JError::raiseError(500, $db->stderr());
			}
		}

		$query = "SHOW COLUMNS FROM #__tj_notification_templates WHERE `Field` = 'core'";
		$db->setQuery($query);
		$check = $db->loadResult();

		if (!$check)
		{
			$query = " ALTER TABLE  `#__tj_notification_templates` ADD  `core` tinyint(3)	AFTER  `user_control`";
			$db->setQuery($query);

			if (!$db->execute())
			{
				JError::raiseError(500, $db->stderr());
			}
		}

		$query = "SHOW COLUMNS FROM #__tj_notification_templates WHERE `Field` = 'replacement_tags'";
		$db->setQuery($query);
		$check = $db->loadResult();

		if (!$check)
		{
			$query = " ALTER TABLE  `#__tj_notification_templates` ADD  `replacement_tags` text	AFTER  `core`";
			$db->setQuery($query);

			if (!$db->execute())
			{
				JError::raiseError(500, $db->stderr());
			}
		}
	}

	/**
	 * Fix database on update
	 *
	 * @return  void
	 *
	 * @Since tjnotification version 1.0
	 */
	public function fix_db_on_update()
	{
		$db = JFactory::getDbo();
		$config = JFactory::getConfig();
		$dbprefix = $config->get('dbprefix');

		$this->fixTemplateTable($db, $dbprefix, $config);
	}
}
