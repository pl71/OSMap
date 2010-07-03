<?php
    
    
// Install any extension in the extensions folder
class com_xmapInstallerScript {

    function __construct($adapter) {
        $this->_loadLanguage();
    }
       
    function install($adapter)
    {
		//$this->_loadLanguage();
        echo '<h2>',JText::_("XMAP_INSTALLING_XMAP"),'</h2>';
        $this->_discoverAndInstallExtensions();
    }
    
    function update($adapter)
    {
        echo '<h2>',JText::_("XMAP_UPGRADING_XMAP"),'</h2>';
        $this->_discoverAndInstallExtensions();
    }
    
	function uninstall($adapter)
	{
		$this->_loadLanguage();
		echo '<h2>',JText::_("XMAP_UNISTALLING_XMAP_EXTENSIONS"),'</h2>';
		$db = JFactory::getDBO();
		require_once JPATH_ADMINISTRATOR.DS.'components'.DS.'com_xmap'.DS.'helpers'.DS.'installer.php';
        $installer = XmapInstaller::getInstance();
		$query = "SELECT extension_id from `#__extensions` where type='xmap_ext'";
        $db->setQuery($query);
		$extensions = $db->loadObjectList();
		$table = JTable::getInstance('Extension');
		foreach ($extensions as $extension) {
			$table->delete($extension->extension_id);
		}
	}

    private function _discoverAndInstallExtensions()
    {
        $db = JFactory::getDBO();
        jimport('joomla.filesystem.file');
        jimport('joomla.filesystem.folder');
        // require_once JPATH_ADMINISTRATOR.DS.'components'.DS.'com_xmap'.DS.'adapters'.DS.'xmap_ext.php';
        require_once JPATH_ADMINISTRATOR.DS.'components'.DS.'com_xmap'.DS.'helpers'.DS.'installer.php';
        $installer = XmapInstaller::getInstance();

        $path = JPATH_ADMINISTRATOR.DS.'components'.DS.'com_xmap'.DS.'extensions';
        if ($folders = JFolder::folders($path)){
            foreach ($folders as $folder) {
                if (JFile::exists($path.DS.$folder.DS.$folder.'.xml')) {
                    $folder = $db->getEscaped($folder);
                    $query = "SELECT extension_id from `#__extensions` where type='xmap_ext' and folder='$folder'";
                    $db->setQuery($query);
                    $id = $db->loadResult();
                    if (!$id) {
                       if ($installer->install($path.DS.$folder)) {
                           echo '<p />'.JText::sprintf('XMAP_INSTALLED_EXTENSION_X',$folder);
                           // Auto-publish the extension if the component is installed
                           $query = "update `#__extensions` set state=1 WHERE type='xmap_ext' and folder='$folder' and folder in (select name from `#__extensions` where name='$folder')";
                           $db->setQuery($query);
                           $db->query();
                       } else {
                           echo '<p />'.JText::sprintf('XMAP_NOT_INSTALLED_EXTENSION_X',$folder);
                       }
                    }
                }
            }
        }
    }
	
	private function _loadLanguage()
	{
        // Load Xmap's language file
        $lang = JFactory::getLanguage();
        $lang->load('com_xmap');
		echo "Loading xmap's language file";	
	}
}