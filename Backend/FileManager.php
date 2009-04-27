<?php
/* Add proper header */

require_once(FileManagerUtility::getPath().'/Upload.php');
require_once(FileManagerUtility::getPath().'./Image.php');

class FileManager {
	
	private $path = null,
		$length = null,
		$basedir = null,
		$basename = null,
		$options,
		$filters = array('image'),
		$post,
		$get;
	
	public function __construct($options){
		$this->options = array_merge(array(
			'directory' => '../Demos/Files',
			'assetBasePath' => '../Assets',
			'dateformat' => 'd.m.y - h:i',
			'maxUploadSize' => 1024*1024*3,
			'filter' => null,
		), $options);
		
		$this->basedir = realpath($this->options['directory']);
		$this->basename = pathinfo($this->basedir, PATHINFO_BASENAME).'/';
		$this->path = realpath($this->options['directory'].'/../');
		$this->length = strlen($this->path);
		
		foreach(array(
			'Expires' => 'Fri, 01 Jan 1990 00:00:00 GMT',
			'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
		) as $key => $value)
			header($key, $value);
		
		/* TODO: Clean this up and fix it! */
		$this->get = $_GET;
		$this->post = $_POST;
	}
	
	public function fireEvent($event){
		$event = $event ? 'on'.ucfirst($event) : null;
		if(!$event || !method_exists($this, $event)) $event = 'onView';
		
		$this->{$event}();
	}
	
	protected function onView(){
		$dir = $this->getDir(!empty($this->post['directory']) ? $this->post['directory'] : null);
		$files = glob($dir.'/*');
		
		if($dir!=$this->basedir) array_unshift($files, $dir.'/..');
		
		natcasesort($files);
		foreach($files as $file){
			$mime = $this->getMimeType($file);
			if($this->options['filter'] && $mime!='text/directory' && !FileManagerUtility::startsWith($mime, $this->options['filter']))
				continue;
			
			$out[is_dir($file) ? 0 : 1][] = array(
				'name' => pathinfo($file, PATHINFO_BASENAME),
				'date' => date($this->options['dateformat'], filemtime($file)),
				'mime' => $this->getMimeType($file),
				'icon' => $this->getIcon($this->normalize($file)),
				'size' => filesize($file),
			);
		}
		
		echo json_encode(array(
			'path' => $this->getPath($dir),
			'dir' => array(
				'name' => pathinfo($dir, PATHINFO_BASENAME),
				'date' => date($this->options['dateformat'], filemtime($dir)),
				'mime' => 'text/directory',
				'icon' => 'dir',
			),
			'files' => array_merge(!empty($out[0]) ? $out[0] : array(), !empty($out[1]) ? $out[1] : array()),
		));
	}
	
	protected function onDetail(){
		if(empty($this->post['directory']) || empty($this->post['file'])) return;
		
		$file = realpath($this->path.'/'.$this->post['directory'].'/'.$this->post['file']);
		if(!$this->checkFile($file)) return;
		
		require_once(FileManagerUtility::getPath().'/Assets/getid3/getid3.php');
		
		$url = $this->normalize(substr($file, strlen($this->path)+1));
		$mime = $this->getMimeType($file);
		$content = null;
		if(FileManagerUtility::startsWith($mime, 'image/')){
			$size = getimagesize($file);
			$content = '<img src="'.$url.'" class="preview" alt="" />
				<h2>${more}</h2>
				<dl>
					<dt>${width}</dt><dd>'.$size[0].'px</dd>
					<dt>${height}</dt><dd>'.$size[1].'px</dd>
				</dl>';
		}elseif(FileManagerUtility::startsWith($mime, 'text/') || $mime=='application/x-javascript'){
			$filecontent = file_get_contents($file, null, null, 0, 300);
			if(!FileManagerUtility::isBinary($filecontent)) $content = '<div class="textpreview">'.nl2br(str_replace(array('$', "\t"), array('&#36;', '&nbsp;&nbsp;'), htmlentities($filecontent))).'</div>';
		}elseif($mime=='application/zip'){
			$out = array(array(), array());
			$getid3 = new getID3();
			$getid3->Analyze($file);
			foreach($getid3->info['zip']['files'] as $name => $size){
				$icon = is_array($size) ? 'dir' : $this->getIcon($name);
				$out[$icon=='dir' ? 0 : 1][$name] = '<li><a><img src="'.$this->options['assetBasePath'].'/Icons/'.$icon.'.png" alt="" /> '.$name.'</a></li>';
			}
			natcasesort($out[0]);
			natcasesort($out[1]);
			$content = '<ul>'.implode(array_merge($out[0], $out[1])).'</ul>';
		}elseif(FileManagerUtility::startsWith($mime, 'audio/')){
			$getid3 = new getID3();
			$getid3->Analyze($file);
			
			$content = '<div class="object">
					<object type="application/x-shockwave-flash" data="'.$this->options['assetBasePath'].'/dewplayer.swf?mp3='.rawurlencode($url).'&volume=30" width="200" height="20">
						<param name="movie" value="'.$this->options['assetBasePath'].'/dewplayer.swf?mp3='.rawurlencode($url).'&volume=30" />
					</object>
				</div>
				<h2>${more}</h2>
				<dl>
					<dt>${title}</dt><dd>'.$getid3->info['comments']['title'][0].'</dd>
					<dt>${artist}</dt><dd>'.$getid3->info['comments']['artist'][0].'</dd>
					<dt>${album}</dt><dd>'.$getid3->info['comments']['album'][0].'</dd>
					<dt>${length}</dt><dd>'.$getid3->info['playtime_string'].'</dd>
					<dt>${bitrate}</dt><dd>'.round($getid3->info['bitrate']/1000).'kbps</dd>
				</dl>';
		}
		
		echo json_encode(array(
			'content' => $content ? $content : '<div class="margin">
					${nopreview}<br/><button value="'.$url.'">${download}</button>
				</div>',
		));
	}
	
	protected function onDestroy(){
		if(empty($this->post['directory']) || empty($this->post['file'])) return;
		
		$file = realpath($this->path.'/'.$this->post['directory'].'/'.$this->post['file']);
		if(!$this->checkFile($file)) return;
		
		$this->unlink($file);
		
		echo json_encode(array(
			'content' => 'destroyed',
		));
	}
	
	protected function onCreate(){
		if(empty($this->post['directory']) || empty($this->post['file'])) return;
		
		$file = $this->getName($this->post['file'], $this->getDir($this->post['directory']));
		if(!$file) return;
		
		mkdir($file);
		
		$this->onView();
	}
	
	protected function onUpload(){
		if(empty($this->get['directory']) || (function_exists('UploadIsAuthenticated') && !UploadIsAuthenticated($this->get))) return;
		
		$dir = $this->getDir($this->get['directory']);
		try{
			$file = Upload::move('Filedata', $dir.'/', array(
				'name' => pathinfo((Upload::exists('Filedata')) ? $this->getName($_FILES['Filedata']['name'], $dir) : null, PATHINFO_FILENAME),
				'size' => $this->options['maxUploadSize'],
				'mimes' => $this->getAllowedMimeTypes(),
			));
			
			if(FileManagerUtility::startsWith(Upload::mime($file), 'image/') && !empty($this->get['resize'])){
				$img = new Image($file);
				$size = $img->getSize();
				if($size['width']>800) $img->resize(800)->save();
				elseif($size['height']>600) $img->resize(null, 600)->save();
			}
			
			echo json_encode(array(
				'status' => 1,
				'name' => pathinfo($file, PATHINFO_BASENAME),
			));
		}catch(UploadException $e){
			echo json_encode(array(
				'status' => 0,
				'error' => class_exists('ValidatorException') ? $e->getMessage() : '${upload.'.$e->getMessage().'}', // This is for Styx :)
			));
		}
	}
	
	/* This method is used by both move and rename */
	protected function onMove(){
		if(empty($this->post['directory']) || empty($this->post['file'])) return;
		
		$rename = empty($this->post['newDirectory']) && !empty($this->post['name']);
		$dir = $this->getDir($this->post['directory']);
		$file = realpath($dir.'/'.$this->post['file']);
		
		$is_dir = is_dir($file);
		if(!$this->checkFile($file) || (!$rename && $is_dir))
			return;
		
		if($rename || $is_dir){
			if(empty($this->post['name'])) return;
			$newname = $this->getName($this->post['name'], $dir);
			$fn = 'rename';
		}else{
			$newname = $this->getName(pathinfo($file, PATHINFO_FILENAME), $this->getDir($this->post['newDirectory']));
			$fn = !empty($this->post['copy']) ? 'copy' : 'rename';
		}
		
		if(!$newname) return;
		
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if($ext) $newname .= '.'.$ext;
		$fn($file, $newname);
		
		echo json_encode(array(
			'name' => pathinfo($this->normalize($newname), PATHINFO_BASENAME),
		));
	}
	
	protected function unlink($file){
		$file = realpath($file);
		if($this->basedir==$file || strlen($this->basedir)>=strlen($file))
			return;
		
		if(is_dir($file)){
			$files = glob($file.'/*');
			if(is_array($files))
				foreach($files as $f)
					$this->unlink($f);
				
			rmdir($file);
		}else{
			try{ if($this->checkFile($file)) unlink($file); }catch(Exception $e){}
		}
	}
	
	protected function getName($file, $dir){
		foreach(glob($dir.'/*') as $f)
			$files[] = pathinfo($f, PATHINFO_FILENAME);
		
		$pathinfo = pathinfo($file);
		$file = $dir.'/'.FileManagerUtility::pagetitle($pathinfo['filename'], $files).(!empty($pathinfo['extension']) ? '.'.$pathinfo['extension'] : null);
		
		return !$file || !FileManagerUtility::startsWith($file, $this->basedir) || file_exists($file) ? null : $file;
	}
	
	protected function getIcon($file){
		if(FileManagerUtility::endsWith($file, '/..')) return 'dir_up';
		else if(is_dir($file)) return 'dir';
		
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		return ($ext && file_exists(realpath($this->options['assetBasePath'].'/Icons/'.$ext.'.png'))) ? $ext : 'default';
	}

	protected function getMimeType($file){
		return is_dir($file) ? 'text/directory' : Upload::mime($file);
	}
	
	protected function getDir($dir){
		$dir = realpath($this->path.'/'.(FileManagerUtility::startsWith($dir, $this->basename) ? $dir : $this->basename));
		return $this->checkFile($dir) ? $dir : $this->basedir;
	}
	
	protected function getPath($file){
		$file = $this->normalize(substr($file, $this->length));
		return substr($file, FileManagerUtility::startsWith($file, '/') ? 1 : 0);
	}
	
	protected function checkFile($file){
		return !(!$file || !FileManagerUtility::startsWith($file, $this->basedir) || !file_exists($file));
	}
	
	protected function normalize($file){
		return preg_replace('/\\\|\/{2,}/', '/', $file);
	}
	
	protected function getAllowedMimeTypes(){
		$filter = $this->options['filter'];
		
		if(!$filter) return null;
		if(!FileManagerUtility::endsWith($filter, '/')) return array($filter);
		
		static $mimes;
		if(!$mimes) $mimes = parse_ini_file(FileManagerUtility::getPath().'/MimeTypes.ini');
		
		foreach($mimes as $mime)
			if(FileManagerUtility::startsWith($mime, $filter))
				$mimeTypes[] = strtolower($mime);
		
		return $mimeTypes;
	}

}

/* Stripped-down version of some Styx PHP Framework-Functionality bundled with this FileBrowser. Styx is located at: http://styx.og5.net */
class FileManagerUtility {
	
	public static function endsWith($string, $look){
		return strrpos($string, $look)===strlen($string)-strlen($look);
	}
	
	public static function startsWith($string, $look){
		return strpos($string, $look)===0;
	}
	
	public static function pagetitle($data, $options = array()){
		static $regex;
		if(!$regex){
			$regex = array(
				explode(' ', 'Æ æ Œ œ ß Ü ü Ö ö Ä ä À Á Â Ã Ä Å &#260; &#258; Ç &#262; &#268; &#270; &#272; Ð È É Ê Ë &#280; &#282; &#286; Ì Í Î Ï &#304; &#321; &#317; &#313; Ñ &#323; &#327; Ò Ó Ô Õ Ö Ø &#336; &#340; &#344; Š &#346; &#350; &#356; &#354; Ù Ú Û Ü &#366; &#368; Ý Ž &#377; &#379; à á â ã ä å &#261; &#259; ç &#263; &#269; &#271; &#273; è é ê ë &#281; &#283; &#287; ì í î ï &#305; &#322; &#318; &#314; ñ &#324; &#328; ð ò ó ô õ ö ø &#337; &#341; &#345; &#347; š &#351; &#357; &#355; ù ú û ü &#367; &#369; ý ÿ ž &#378; &#380;'),
				explode(' ', 'Ae ae Oe oe ss Ue ue Oe oe Ae ae A A A A A A A A C C C D D D E E E E E E G I I I I I L L L N N N O O O O O O O R R S S S T T U U U U U U Y Z Z Z a a a a a a a a c c c d d e e e e e e g i i i i i l l l n n n o o o o o o o o r r s s s t t u u u u u u y y z z z'),
			);
			
			$regex[0][] = '"';
			$regex[0][] = "'";
		}
		
		$data = trim(substr(preg_replace('/(?:[^A-z0-9]|_|\^)+/i', '_', str_replace($regex[0], $regex[1], $data)), 0, 64), '_');
		return !empty($options) ? self::checkTitle($data, $options) : $data;
	}
	
	protected static function checkTitle($data, $options = array(), $i = 0){
		if(!is_array($options)) return $data;
		
		foreach($options as $content)
			if($content && strtolower($content)==strtolower($data.($i ? '_'.$i : '')))
				return self::checkTitle($data, $options, ++$i);
		
		return $data.($i ? '_'.$i : '');
	}
	
	public static function isBinary($str){
		$array = array(0, 255);
		for($i = 0; $i < strlen($str); $i++)
			if(in_array(ord($str[$i]), $array)) return true;
		
		return false;
	}
	
	public static function getPath(){
		static $path;
		return $path ? $path : $path = pathinfo(__FILE__, PATHINFO_DIRNAME);
	}
	
}