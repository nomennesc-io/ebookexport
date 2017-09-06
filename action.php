<?php
/**
 * DokuWiki Plugin ebookexport (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Nomen Nescio <info@nomennesc.io>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_ebookexport extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
       $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'exportepub', array());
       $controller->register_hook('TEMPLATE_PAGETOOLS_DISPLAY', 'BEFORE', $this, 'addepubbutton', array());
    }

    public function exportepub(Doku_Event $event) {
        global $ACT;
        global $ID;
        global $conf;

        // our event?
        if($ACT != 'export_epub') return false;

        // check user's rights
        if(auth_quickaclcheck($ID) < AUTH_READ) return false;

        // it's ours, no one else's
        $event->preventDefault();

	$tempdir=tempnam(sys_get_temp_dir(),'');
	if (file_exists($tempdir)) { unlink($tempdir); }
	mkdir($tempdir);

        require_once DOKU_INC . 'inc/parser/parser.php';

	$mypage = pageinfo();
	$mypath = $mypage["filepath"];
	$doc = file_get_contents($mypath);

	$instructions = p_get_instructions($doc);
	require_once DOKU_INC . 'inc/parser/xhtml.php';
	$Renderer = new Doku_Renderer_XHTML();
	foreach ( $instructions as $instruction ) {
	    call_user_func_array(array(&$Renderer, $instruction[0]),$instruction[1]);
	} 

	$tocarray = $Renderer->toc;
	$pages = $Renderer->doc;

	if(isset($conf['baseurl']) && ($conf['baseurl'] != '')){
	    $url = $conf['baseurl'] . '/';
	}
	else{
            $self = parse_url(DOKU_URL);
            $url = $self['scheme'] . '://' . $self['host'];
            if($self['port']) {
                $url .= ':' . $self['port'];
            }
	    $url .= '/';
	}
	$pages = preg_replace('/href="\//',"href=\"$url",$pages);

	file_put_contents($tempdir . "/pages.xhtml",$pages);

	$mimetype = "application/epub+zip";
        file_put_contents($tempdir . "/mimetype",$mimetype);

	$pagestyle = "@page {\nmargin-bottom: 5pt;\nmargin-top: 5pt;\n}\n";
        file_put_contents($tempdir . "/page_styles.css",$pagestyle);

	$stylesheet = "h2 {\nfont-size: large;\n}\n";
        file_put_contents($tempdir . "/stylesheet.css",$stylesheet);

	mkdir($tempdir . "/META-INF");

	$container = '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>';
	file_put_contents($tempdir . "/META-INF/container.xml", $container);

	if(isset($mypage["meta"]["title"])){
	    $mytitle = $mypage["meta"]["title"];
	} else{
	    $mytitle = preg_replace('/^.*:/','',$ID);
	    $mytitle = ucwords(preg_replace('/_/',' ',$mytitle));
	}

	$epubuuid = preg_replace('/^(........)(....)(....)(....)(............).*/','${1}-${2}-${3}-${4}-${5}',md5($conf['title'] . $mytitle . $mypage['lastmod']));
	$files = array('content.opf','mimetype','page_styles.css','pages.xhtml','stylesheet.css','titlepage.xhtml','toc.ncx','META-INF/container.xml');

	$content = "<?xml version='1.0' encoding='utf-8'?>";
	$content .= '<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="uuid_id">';
	$content .= '<metadata xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:calibre="http://calibre.kovidgoyal.net/2009/metadata" xmlns:dc="http://purl.org/dc/elements/1.1/">';
	$content .= '<dc:language>en</dc:language>';
	$content .= '<dc:title>' . $mytitle . '</dc:title>';
	$content .= '<dc:creator opf:file-as="' . $conf['title'] . '" opf:role="aut">' . $conf['title'] . '</dc:creator>';
	$content .= '<meta name="cover" content="cover"/>';
	$content .= '<dc:date>' . date("Y-m-d\TH:i:s:P",$mypage['lastmod']) . '</dc:date>';
	$content .= '<dc:contributor opf:role="bkp"></dc:contributor>';
	$content .= '<dc:identifier id="uuid_id" opf:scheme="uuid">' . $epubuuid . '</dc:identifier>';
	$content .= '</metadata>';
	$content .= '<manifest>';
	if(isset($mypage["meta"]["relation"]["media"])){
            foreach($mypage["meta"]["relation"]["media"] as $mediaitem => $whatever){
            	$mediafile = mediaFN($mediaitem);
		$mediamime = mime_content_type($mediafile);
                $medialink = ml($mediaitem);
                $mediadir = dirname($medialink);
		if(!is_dir($tempdir . $mediadir))
		    mkdir($tempdir . $mediadir, 0777, true);
		copy($mediafile,$tempdir . $medialink);
		array_push($files,substr($medialink,1));
		$content .= '<item href="' . $medialink . '" id="' . $mediaitem . '" media-type="' . $mediamime . '"/>';
            }
	}
	$content .= '<item href="pages.xhtml" id="id1" media-type="application/xhtml+xml"/>';
	$content .= '<item href="page_styles.css" id="page_css" media-type="text/css"/>';
	$content .= '<item href="stylesheet.css" id="css" media-type="text/css"/>';
	$content .= '<item href="titlepage.xhtml" id="titlepage" media-type="application/xhtml+xml"/>';
	$content .= '<item href="toc.ncx" media-type="application/x-dtbncx+xml" id="ncx"/>';
	$content .= '</manifest>';
	$content .= '<spine toc="ncx">';
	$content .= '<itemref idref="titlepage"/>';
	$content .= '<itemref idref="id1"/>';
	$content .= '</spine>';
	$content .= '<guide>';
	$content .= '<reference href="titlepage.xhtml" type="cover" title="Cover"/>';
	$content .= '</guide></package>';
        file_put_contents($tempdir . "/content.opf",$content);

	$titlepage = "<?xml version='1.0' encoding='utf-8'?>";
	$titlepage .= '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">';
	$titlepage .= '<head>';
	$titlepage .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>';
	$titlepage .= '<title>Cover</title>';
	$titlepage .= '<style type="text/css" title="override_css">';
	$titlepage .= '@page {padding: 0pt; margin:0pt}';
	$titlepage .= 'body { text-align: center; padding:0pt; margin: 0pt; }';
	$titlepage .= '</style>';
	$titlepage .= '</head>';
	$titlepage .= '<body>';
	$titlepage .= '<div>';
	$titlepage .= '<h1>' . $mytitle . '</h1>';
	$titlepage .= '<h2>' . $conf['title'] . '</h2>';
	$titlepage .= '</div>';
	$titlepage .= '</body></html>';
        file_put_contents($tempdir . "/titlepage.xhtml",$titlepage);

	$toc = "<?xml version='1.0' encoding='utf-8'?>";
	$toc .= '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="eng">';
	$toc .= '<head>';
	$toc .= '<meta content="0c159d12-f5fe-4323-8194-f5c652b89f5c" name="dtb:uid"/>';
	$toc .= '<meta content="2" name="dtb:depth"/>';
	$toc .= '<meta content="calibre (0.8.68)" name="dtb:generator"/>';
	$toc .= '<meta content="0" name="dtb:totalPageCount"/>';
	$toc .= '<meta content="0" name="dtb:maxPageNumber"/>';
	$toc .= '</head>';
	$toc .= '<docTitle>';
	$toc .= '<text>Titel</text>';
	$toc .= '</docTitle>';
	$toc .= '<navMap>';
	for($i=0;$i<sizeof($tocarray);$i++){
	    $toc .= '<navPoint id="' . substr($tocarray[$i]["link"],1) . '" playOrder="' . $i . '">';
	    $toc .= '<navLabel><text>' . $tocarray[$i]["title"] . '</text>';
	    $toc .= '</navLabel><content src="pages.xhtml' . $tocarray[$i]["link"] . '"/>';
	    $toc .= '</navPoint>';
	}
	$toc .= '</navMap></ncx>';
        file_put_contents($tempdir . "/toc.ncx",$toc);

	$zip = new ZipArchive();
	$zipfile = $tempdir . "/" . $ID . ".epub";
	$zip->open($zipfile,ZipArchive::CREATE);
	foreach($files as $file){
		$zip->addFile($tempdir . "/" . $file,$file);
	}
	$zip->close();

        header('Content-Type: application/epub+zip');
        header('Cache-Control: must-revalidate, no-cache');
        header('Pragma: no-cache');

        $filename = preg_replace('/ /','_',$mytitle) . ".epub";
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //Bookcreator uses jQuery.fileDownload.js, which requires a cookie.
        header('Set-Cookie: fileDownload=true; path=/');

        $fp = @fopen($zipfile, "rb");
        if($fp) {
	    $size = filesize($zipfile);
	    header('Accept-Ranges: bytes');
            header("Content-Length: $size");
	    fseek($fp,0);
	    $chunk = ($size > HTTP_CHUNK_SIZE) ? HTTP_CHUNK_SIZE : $size;
	    while (!feof($fp) && $chunk > 0) {
		@set_time_limit(30);
		print fread($fp, $chunk);
		flush();
		$size -= $chunk;
		$chunk = ($size > HTTP_CHUNK_SIZE) ? HTTP_CHUNK_SIZE : $size;
	    }
        } else {
            header("HTTP/1.0 500 Internal Server Error");
            print "Could not read file - bad permissions?";
            $ranges[] = array(0,$size,$size);
        }
	$this->rrmdir($tempdir);
	exit;
    }

    private function rrmdir($dir) { 
        if (is_dir($dir)) { 
            $objects = scandir($dir); 
            foreach ($objects as $object) { 
                if ($object != "." && $object != "..") { 
                    if (is_dir($dir."/".$object))
                        $this->rrmdir($dir."/".$object);
                    else
                        unlink($dir."/".$object); 
                } 
            }
            rmdir($dir); 
        } 
    }

    public function addepubbutton(Doku_Event $event) {
        global $ID, $REV;

        if($event->data['view'] == 'main') {
            $params = array('do' => 'export_epub');
            if($REV) {
                $params['rev'] = $REV;
            }

            // insert button at position before last (up to top)
            $event->data['items'] = array_slice($event->data['items'], 0, -1, true) +
                array('export_epub' =>
                          '<li>'
                          . '<a href="' . wl($ID, $params) . '"  class="action export_epub" rel="nofollow" title="Export to EPUB">'
                          . '<span>Export to EPUB</span>'
                          . '</a>'
                          . '</li>'
                ) +
                array_slice($event->data['items'], -1, 1, true);
        }
    }
}

// vim:ts=4:sw=4:et:
