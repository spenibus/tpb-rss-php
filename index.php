<?
/*******************************************************************************
TPB-RSS
creation: 2014-11-22 10:16 +0000
  update: 2014-11-26 08:10 +0000

usage:
   ?path=/search/cat
*******************************************************************************/




/******************************************************************************/
error_reporting(!E_ALL);
//error_reporting(E_WARNING);
mb_internal_encoding('UTF-8');




/******************************************************************************/
date_default_timezone_set('Europe/Stockholm');

$CFG_DIR_CACHE            = 'cache/';
$CFG_TIME                 = time();
$CFG_URL_TPB              = 'https://thepiratebay.se';
$CFG_CACHE_AGE_MAX        = 300;
$CFG_CACHE_VACUUM_AGE_MAX = 86400;




/******************************************************************************/
function hsc($str) {
   return htmlspecialchars($str, ENT_COMPAT, 'UTF-8');
}




/******************************************************************************/
function returnFeed($str) {
   header('content-type: application/xml');
   exit($str);
}




/******************************************************************************/
function vacuumCache() {

   global $CFG_DIR_CACHE, $CFG_TIME, $CFG_CACHE_VACUUM_AGE_MAX;

   $vacuumFile = $CFG_DIR_CACHE.'_vacuum';

   // never vacuumed or last vacuum > 24hours
   $vacuum = !is_file($vacuumFile) || $CFG_TIME-filemtime($vacuumFile) > $CFG_CACHE_VACUUM_AGE_MAX
      ? true
      : false;

   // vacuum unnecessary, abort
   if(!$vacuum) {
      return 0;
   }

   // vacuum old cache files
   $d = new DirectoryIterator($CFG_DIR_CACHE);
   foreach($d as $f) {
      $fn = $f->getFilename();
      $fp = $f->getRealPath();

      if(is_file($fp) && substr($fn, 0, 6) == 'cache-') {

         // file age
         $fa = $CFG_TIME - $f->getMTime();

         // file is old (24 hours), delete
         if($fa > $CFG_CACHE_VACUUM_AGE_MAX) {
            unlink($fp);
         }
      }
   }

   // update last vacuum time
   touch($vacuumFile);
}




/******************************************************************************/
function timeExtractor($str) {

   // init
   $year = $month = $day = $hour = $minute = 0;

   // parse time string
   preg_match(
      '/Uploaded ((Today|Y-day).)?((\d\d)-(\d\d).)?((\d\d):(\d\d)|(\d\d\d\d))?((\d+).mins.ago)?, Size/siu',
      $str,
      $m
   );


   // year
   $year = (int)($m[9] ? $m[9] : date('Y'));

   // month
   $month = (int)($m[4] ? $m[4] : date('m'));

   // day
   $day = (int)($m[5] ? $m[5] : date('d'));
   if($m[2] == 'Y-day') {
      --$day;
   }

   // hour
   $hour = (int)($m[7] ? $m[7] : 0);
   if($m[11]) {
      $hour = (int)date('H');
   }

   // minute
   $minute = (int)($m[8] ? $m[8] : 0);
   if($m[11]) {
      $minute = (int)date('i') - (int)$m[11];
   }

   // timestamp
   $time = mktime($hour, $minute, 0, $month, $day, $year);

   return $time;
}




/**************************************************************** pre-process */

// check cache dir
if(!is_dir($CFG_DIR_CACHE)) {
   mkdir($CFG_DIR_CACHE, 0777, true);
}


// vacuum cache
vacuumCache();




/*************************************************************** process path */
if($_GET['path']) {

   // tpb path
   $path = $_GET['path'];


   // cache data
   $cacheFile = $CFG_DIR_CACHE.'cache-'.sha1($path).'.xml';
   $cacheAge  = $CFG_TIME - filemtime($cacheFile);


   // serve cache if fresh
   if(is_file($cacheFile) && $cacheAge < $CFG_CACHE_AGE_MAX) {
      header('tpb-rss: notice-served-from-cache-'.$cacheAge);
      returnFeed(file_get_contents($cacheFile));
   }


   // load remote content
   $source = file_get_contents($CFG_URL_TPB.$path);


   // no content received, abort
   if(strlen($source) == 0) {
      header('tpb-rss: error-source-no-content');
      exit();
   }


   // build DOM
   libxml_use_internal_errors(true);
   $doc = new DOMDocument();
   $doc->loadHTML($source);


   // init items list
   $items = array();


   // get table rows from #searchResult
   $rows = $doc->getElementById('searchResult')->getElementsByTagName('tr');
   foreach($rows as $row) {

      // init tag occurence counter per row
      $tagCounter = array();


      // init item data holder
      $item = array();


      // get all descendants
      $nodes = $row->getElementsByTagName('*');
      foreach($nodes as $node) {

         // count tag occurence
         ++$tagCounter[$node->tagName];


         // init node data holder
         $nodeData = array(
            'occurence'   => $node->tagName.'-'.$tagCounter[$node->tagName],
            'tagName'     => $node->tagName,
            'textContent' => $node->textContent,
            'attributes'  => array()
         );


         // get node attributes
         foreach($node->attributes as $attr) {
            $nodeData['attributes'][$attr->name] = $attr->value;
         }


         // build item data
         if($nodeData['attributes']['class'] == 'detLink') {
            $item['title'] = $nodeData['textContent'];
            $item['guid']  = $nodeData['attributes']['href'];
            $item['id']    = explode('/', $nodeData['attributes']['href'])[2];
         }
         elseif($nodeData['tagName'] == 'a' && substr($nodeData['attributes']['href'],0,7) == 'magnet:') {
            $item['link'] = $nodeData['attributes']['href'];
         }
         elseif($nodeData['tagName'] == 'font' && $nodeData['attributes']['class'] == 'detDesc') {
            $item['time'] = timeExtractor($nodeData['textContent']);
            $item['desc'] = $nodeData['textContent'];
         }
         elseif($nodeData['occurence'] == 'td-3') {
            $item['meta:seeds'] = $nodeData['textContent'];
         }
         elseif($nodeData['occurence'] == 'td-4') {
            $item['meta:leechers'] = $nodeData['textContent'];
         }
      }

      // add item to items list
      if($item['link']) {
         $items[] = $item;
      }
   }


   // sort items by id desc (should be more precise than time)
   usort($items, function($a, $b) {
      return $b['id'] - $a['id'];
   });


   // build rss items
   $rss_items = '';
   foreach($items as $item) {
      $rss_items .= '
      <item>
         <title>'.hsc($item['title']).'</title>
         <link>'.hsc($item['link']).'</link>
         <pubDate>'.gmdate(DATE_RSS, $item['time']).'</pubDate>
         <guid>'.hsc($item['guid']).'</guid>
         <description>'.hsc(
            'S:'.$item['meta:seeds']
            .' L:'.$item['meta:leechers']
            .' '.$item['desc']
         ).'</description>
      </item>';
   }


   // finalize rss
   $rss = '<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
   <channel>
      <title>TPB-RSS - '.hsc($path).'</title>
      <pubDate>'.gmdate(DATE_RSS).'</pubDate>
      <link>'.hsc('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']).'</link>
      <description></description>'.
      $rss_items.'
   </channel>
</rss>';


   // save cache
   file_put_contents($cacheFile, $rss);


   header('tpb-rss: notice-served-fresh');
   returnFeed($rss);
}




/******************************************************************************/
exit('ready');
?>