<?php
namespace GZCrawler;

class Crawler {
  protected $baseUrl;
  protected $tmpDir;

  protected $fileFilter = [];

  protected $typeDict;
  protected $regionDict;

  protected $zipDone;
  protected $newItem;

  /** 
   *  @param mixed[] $options {
   *    @type string "tmpDir" directory to seve downloaded files, 'tmp' by default
   *    @type string "baseUrl" default is: ftp://fz223free:fz223free@ftp.zakupki.gov.ru/out/published
   *    @type callable "zipDone" function will be called when whole zip file parsing done. File name 
   *      will be passed as argument
   *    @type callable "newItem" function will be called after each parsed item.
   *    @type mixed[] "fileFilter" {
   *      @type string[] "types" array of precedure types. You can view list of 
   *        possible names at ./types.php or define custom type with 'customProcTypes' 
   *        option. By default, all types will be loaded.
   *      @type string[] "regions" array of regions name to load data from. If not 
   *        set, data for all regions will be loaded. Check ./regions.php for possible 
   *        values.
   *      @type Date "dateFrom"
   *      @type Date "dateTo"
   *      @type callable "customFilter" function that retrives array of .zip file urls
   *        as argument. Returned array of urls will be downloaded.
   *    }
   *  }
   */
  function __construct($options=[]) {
    $this->typeDict = require(__DIR__.'/types.php');
    $this->regionDict = require(__DIR__.'/regions.php');
    
    $this->tmpDir = array_key_exists('tmpDir', $options)
      ? $options['tmpDir']
      : sys_get_temp_dir();
    $this->baseUrl = array_key_exists('baseUrl', $options)
      ? $options['baseUrl']
      : 'ftp://fz223free:fz223free@ftp.zakupki.gov.ru/out/published';

    if(array_key_exists('zipDone', $options))
      $this->zipDone = $options['zipDone'];
    if(array_key_exists('newItem', $options))
      $this->newItem = $options['newItem'];

    $this->fileFilter = [
      'dateFrom' => null,
      'dateTo' => null,
      'types' => null,
      'regions' => null,
      'customFilter' => null
    ];
    if (array_key_exists('fileFilter', $options))
      $this->fileFilter = array_merge($this->fileFilter, $options['fileFilter']);
  }

  public function generateDirUrl($type, $region) {
    return "{$this->baseUrl}/$region/$type/daily/";
  }
  
  public function lsDir($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FTPLISTONLY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($ch);
    curl_close($ch);
    if(!$res) {
      throw new \Exception("cannot load directory content at $path".PHP_EOL.'curl: '.curl_error($ch));
    }
    //curl response contains eol at the end, which becomes empty string after explode
    return array_slice(explode(PHP_EOL, $res), 0, -1);
  }

  public function extractTypeFromFileName($filename) {
    if(!preg_match('/^([a-zA-Z]+).+_\d{8}_\d{6}_\d{8}_\d{6}_daily_\d{3}.xml\z/', $filename, $matches)) {
      throw new \Exception("cannot extract type from filename: $filename");
    }
    return $matches[1];
  }

  public function extractDateFromFileName($filename) {
    if(!preg_match('/(\d{8})_\d{6}_\d{8}_\d{6}_daily_\d{3}.xml.zip\z/', $filename, $matches)) {
      throw new \Exception("cannot extract date from filename: $filename");
    }
    return \DateTime::createFromFormat(
      'Ymd H:i:s',
      "{$matches[1]} 00:00:00"
    );
  }

  public function generateFilesList() {
    $dirs = $this->generateDirectories();
    $files = [];
    foreach($dirs as $dir) {
      $dir_files = $this->lsDir($dir);
      foreach($dir_files as $file) {
        if($this->filterFileByDate($file))
          $files[]=$file;
      }
    }
    $result = [];
    if(array_key_exists('fileFilter', $this->options)) {
      $func = $this->options['fileFilter'];
      if(array_key_exists('fileFilterBatch', $this->options)) {
        $batch_size = $this->options['fileFilterBatch'];
        if($batch_size>0) {
          while($batch = array_splice($files, $batch_size)) {
            $result = array_merge($result, $batch);
          }
          return $result;
        }
      }
      return $func($files);
    }
    return $files;
  }


  public function downloadFile($file) {
    $filename = basename(parse_url($file, PHP_URL_PATH));
    echo "downloading $filename".PHP_EOL;
    $file_path = $this->tmpDir."/$filename";
    $fp = fopen($file_path, "w");
    $ch = curl_init($file);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $file_path;
  }

  public function extractZip($file) {
    $xml_files = [];
    $zip = new \ZipArchive;
    $res = $zip->open($file);
    if($res) {
      if($zip->extractTo($this->tmpDir)) {
        for($i=0; $i<$zip->numFiles; $i++) {
          $filename = $zip->getNameIndex($i);
          $xml_files[] = "{$this->tmpDir}/$filename";
        }
      }
    } else {
      throw new Exception("cannot open zip: $filepath");
    }
    $zip->close();
    unlink($file);
    return $xml_files;
  }
  
  public function parseXml($xml_file, $type) {
    $template = $this->typeDict[$type];

    $xml_content = file_get_contents($xml_file);

    if(!$xml_content) {
      return null;
    }

    //avoid default namespace redifining
    $root = new \SimpleXMLElement(str_replace('xmlns=', 'ns=', $xml_content));
    
    $namespaces = $root->getDocNamespaces();

    $registerNS = function ($xml_el) use ($namespaces) {
      foreach($namespaces as $name => $url) {
        $xml_el->registerXPathNamespace($name, $url);
      }
    };
    $registerNS($root);
    $arr = $root->xpath("ns2:body/ns2:item/ns2:{$type}Data");
    $root = reset($arr);

    $parseNode = function($node, $template) use (&$parseNode, $registerNS) {
      $registerNS($node);
      $item = [];
      foreach($template as $key => $props) {
        $data_type = is_string($props) ? 'text' : $props['type'];
        $xpath = is_string($props) ? $props : $props['xpath'];
        $target = $node->xpath($xpath);

        switch($data_type) {
          case 'text':
            $item[$key] = (string) reset($target);
            break;
          case 'date':
            $item[$key] = new \DateTime(reset($target));
            break;
          case 'array':
            $element_template = $template[$key]['element'];
            $item[$key] = array_map(
              function($node) use ($parseNode, $element_template) {
                return $parseNode($node,$element_template); 
              },
              $target
            );
            break;
        }
      }
      return $item;
    };
    return $parseNode($root, $template);
  }

  public function start() {
    $types = $this->fileFilter['types']
      ? $this->fileFilter['types']
      : array_keys($this->typeDict);
    $regions = $this->fileFilter['regions']
      ? $this->fileFilter['regions']
      : $this->regionDict;

    $dirs = array_reduce(
      $types,
      function($carry, $type) use ($regions) {
        return array_merge(
          $carry,
          array_map(
            function($region) use ($type) {
              return $this->generateDirUrl($type, $region);
            },
            $regions
          )
        );
      },
      []
    );

    $urls = array_reduce(
      $dirs,
      function($carry, $dir) {
        return array_merge(
          $carry,
          array_map(
            function($filename) use ($dir) {
              return "$dir/$filename";
            },
            $this->lsDir($dir)
          )
        );
      },
      []
    );

    $urls = array_filter(
      $urls,
      function($file) {
        $date = $this->extractDateFromFileName($file);
        return 
          !($this->fileFilter['dateFrom'] && $date < $this->fileFilter['dateFrom'])
          && !($this->fileFilter['dateTo'] && $date > $this->fileFilter['dateTo']);
      }
    );

    if($this->fileFilter['customFilter']) {
      $urls = $this->fileFilter['customFilter']($urls);
    }

    foreach($urls as $url) {
      $zip = $this->downloadFile($url);
      $xml_files = $this->extractZip($zip);
      foreach($xml_files as $xml_file) {
        $filename = basename(parse_url($xml_file, PHP_URL_PATH));
	$item = $this->parseXml($xml_file, $this->extractTypeFromFileName($filename));
        unlink($xml_file);
        if($item && $this->newItem)
          ($this->newItem)($item);
      }
      if($this->zipDone)
        ($this->zipDone)($url);
    }
  }
}
