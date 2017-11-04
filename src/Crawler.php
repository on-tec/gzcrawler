<?php

namespace GZCrawler;

class Crawler
{

  const TYPE_223_REGIONAL_DAILY = 1;
  const TYPE_223_NSI = 2;
  const TYPE_44_REGIONAL_CURR_MONTH = 3;

  public $zipDone;

  protected $baseUrl;
  protected $type;
  protected $tmpDir;
  protected $xmlDone;
  protected $newItem;

  protected $typeDict;
  protected $regionDict;

  //TYPE_223_REGIONAL_DAILY options
  protected $dateFrom;
  protected $dateTo;
  protected $regions;
  protected $preDownload;

  /**
   * @param mixed[] $options {
   * @type string "tmpDir"
   * @type integer "type" TYPE_223_REGIONAL_DAILY or TYPE_223_NSI
   *
   * @type callable "zipDone" function will be
   *    called when whole zip file parsing done.
   *    Zip url will be passed as argument
   * @type callable "xmlDone" function will be
   *    called when xml file parsing done.
   *    File name will be passed as argument.
   * @type callable "newItem" function will be
   *    called after each parsed item.
   *
   * @type string[] "regions" array of regions name to load data from.
   *    If not set, data for all regions will be loaded.
   *    Check ./regions.php for possible values.
   * @type Date "dateFrom"
   * @type Date "dateTo"
   * @type callable "preDownload" function that retrives array of
   *    .zip file urls as argument. Returned array
   *    of urls will be downloaded.
   *
   *  }
   */
  function __construct($options = [])
  {
    $this->typeDict = require(__DIR__ . '/types.php');
    $this->regionDict = require(__DIR__ . '/regions.php');

    $this->baseUrl = isset($options['baseUrl'])
      ? $options['baseUrl']
      : 'ftp://fz223free:fz223free@ftp.zakupki.gov.ru/out';

    $this->tmpDir = isset($options['tmpDir'])
      ? $options['tmpDir']
      : sys_get_temp_dir();

    $this->type = isset($options['type'])
      ? $options['type']
      : Crawler::TYPE_223_REGIONAL_DAILY;

    if ($this->type == self::TYPE_44_REGIONAL_CURR_MONTH)
      $this->baseUrl = 'ftp://free:free@ftp.zakupki.gov.ru';

    $this->zipDone = isset($options['zipDone'])
      ? $options['zipDone']
      : null;
    $this->xmlDone = isset($options['xmlDone'])
      ? $options['xmlDone']
      : null;
    $this->newItem = isset($options['newItem'])
      ? $options['newItem']
      : null;

    $this->regions = isset($options['regions'])
      ? $options['regions']
      : $this->regionDict;
    $this->dateTo = isset($options['dateTo'])
      ? $options['dateTo']
      : null;
    $this->dateFrom = isset($options['dateFrom'])
      ? $options['dateFrom']
      : null;
    $this->preDownload = isset($options['preDownload'])
      ? $options['preDownload']
      : null;
  }

  public function crawl($doc, $data_map)
  {
    $urls = $this->generate_file_list($doc);
    foreach ($urls as $url) {
      $zip = $this->downloadFile($url);
      $xml_files = $this->extractZip($zip);

      foreach ($xml_files as $xml_file) {
        $fileinfo = pathinfo($xml_file);
        $isXML = $fileinfo['extension'] == 'xml';

        if ($isXML)
          $items = $this->parseXml($xml_file, $data_map, $doc);

        unlink($xml_file);

        if (!$isXML)
          continue;

        if ($this->xmlDone)
          call_user_func($this->xmlDone, $xml_file);
        if ($items && $this->newItem) {
          foreach ($items as $item)
            call_user_func($this->newItem, $item, $url, $doc);
        }
      }
      if ($this->zipDone)
        call_user_func($this->zipDone, $url);
    }
  }

  public function generate_file_list($doc)
  {
    $res = [];
    switch ($this->type) {
      case Crawler::TYPE_223_REGIONAL_DAILY:
        $res = $this->regional_daily_files($doc);
        break;
      case Crawler::TYPE_223_NSI:
        $res = [$this->latest_nsi_zip($doc)];
        break;
      case Crawler::TYPE_44_REGIONAL_CURR_MONTH:
        $res = $this->regional_curr_month_files($doc);
        break;
    }
    if ($this->preDownload) {
      $res = call_user_func($this->preDownload, $res);
    }
    return $res;
  }

  public function regional_daily_files($doc)
  {
    $dirs = array_map(
      function ($region) use ($doc) {
        return $this->generate_regional_dir_url($doc, $region);
      },
      $this->regions
    );

    $urls = array_reduce(
      $dirs,
      function ($carry, $dir) {
        return array_merge(
          $carry,
          array_map(
            function ($filename) use ($dir) {
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
      function ($file) {
        $date = $this->extractDateFromFileName($file);
        return
          !($this->dateFrom && $date <= $this->dateFrom)
          && !($this->dateTo && $date >= $this->dateTo);
      }
    );
    return $urls;
  }

  public function generate_regional_dir_url($doc, $region)
  {
    return "{$this->baseUrl}/published/$region/$doc/daily/";
  }

  public function lsDir($url)
  {
    echo 'fetch files in ' . $url . PHP_EOL;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FTPLISTONLY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($ch);
    if (!$res) {
      throw new \Exception("cannot load directory content at $url" . PHP_EOL . 'curl: ' . curl_error($ch));
    }
    curl_close($ch);
    //curl response contains eol at the end, which becomes empty string after explode
    return array_slice(explode(PHP_EOL, $res), 0, -1);
  }

  public function extractDateFromFileName($filename)
  {
    if (!preg_match('/(\d{8})_\d{6}_\d{8}_\d{6}_daily_\d{3,4}.xml.zip\z/', $filename, $matches)) {
      throw new \Exception("cannot extract date from filename: $filename");
    }
    return \DateTime::createFromFormat(
      'Ymd H:i:s',
      "{$matches[1]} 00:00:00"
    );
  }

  public function latest_nsi_zip($doc)
  {
    $dir = $this->generate_nsi_dir_url($doc);
    $files = $this->lsDir($dir);
    sort($files);
    return $dir . '/' . end($files);
  }

  public function generate_nsi_dir_url($doc)
  {
    return "{$this->baseUrl}/nsi/$doc/";
  }

  public function regional_curr_month_files($doc)
  {
    $dirs = array_map(
      function ($region) use ($doc) {
        return "{$this->baseUrl}/fcs_regions/$region/$doc/currMonth/";
      },
      $this->regions
    );

    $urls = array_reduce(
      $dirs,
      function ($carry, $dir) {
        return array_merge(
          $carry,
          array_map(
            function ($filename) use ($dir) {
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
      function ($file) {
        $date = $this->extractDateFromFileNameFZ44($file);
        return
          !($this->dateFrom && $date <= $this->dateFrom)
          && !($this->dateTo && $date >= $this->dateTo);
      }
    );
    return $urls;
  }

  public function extractDateFromFileNameFZ44($filename)
  {
    if (!preg_match('/(\d{8})\d{2}_\S+?.xml.zip\z/', $filename, $matches)) {
      throw new \Exception("cannot extract date from filename: $filename");
    }
    return \DateTime::createFromFormat(
      'Ymd H:i:s',
      "{$matches[1]} 00:00:00"
    );
  }

  public function downloadFile($file)
  {
    echo 'downloading file: ' . $file . PHP_EOL;
    $filename = basename(parse_url($file, PHP_URL_PATH));
    $file_path = $this->tmpDir . "/$filename";
    $fp = fopen($file_path, "w");
    $ch = curl_init($file);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $file_path;
  }

  public function extractZip($file)
  {
    echo 'extracting zip: ' . $file . PHP_EOL;
    $xml_files = [];
    $zip = new \ZipArchive;
    $res = $zip->open($file);
    if ($res) {
      if ($zip->extractTo($this->tmpDir)) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
          $filename = $zip->getNameIndex($i);
          $xml_files[] = "{$this->tmpDir}/$filename";
        }
      }
    } else {
      throw new Exception("cannot open zip: $file");
    }
    $zip->close();
    unlink($file);
    return $xml_files;
  }

  public function parseXml($xml_file, $data_map, $doc)
  {
    $xml_content = file_get_contents($xml_file);

    if (!$xml_content) {
      return null;
    }

    //avoid default namespace redifining
    $root = new \SimpleXMLElement(str_replace('xmlns=', 'ns=', $xml_content));

    $namespaces = $root->getDocNamespaces();

    $registerNS = function ($xml_el) use ($namespaces) {
      foreach ($namespaces as $name => $url) {
        $xml_el->registerXPathNamespace($name, $url);
      }
    };

    $registerNS($root);

    $parseNode = function ($node, $map, $docType = '') use (&$parseNode, $registerNS) {
      $registerNS($node);
      $item = [];
      foreach ($map as $key => $props) {
        $data_type = is_string($props) ? 'text' : $props['type'];
        $xpath = is_string($props) ? $props : $props['xpath'];

        if (!$xpath)
          continue;

        $target = $node->xpath($xpath);

        switch ($data_type) {
          case 'integer':
            $item[$key] = (int)reset($target);
            break;
          case 'text':
            $item[$key] = (string)reset($target);
            break;
          case 'date':
            $item[$key] = new \DateTime(reset($target));
            break;
          case 'docType':
            $item[$key] = $docType;
            break;
          case 'array':
            $element_map = $map[$key]['element'];
            $item[$key] = array_map(
              function ($node) use ($parseNode, $element_map) {
                return $parseNode($node, $element_map);
              },
              $target
            );
            break;
        }
      }
      return $item;
    };

    switch ($this->type) {
      case self::TYPE_44_REGIONAL_CURR_MONTH:
        $rootData = $root->xpath("//*[starts-with(name(), 'ns2:fcs')]|//fcsContractSign");
        $docType = isset($rootData[0]) ? $rootData[0]->getName() : '';
        break;
      default:
        $rootData = $root->xpath("ns2:body/ns2:item/ns2:{$doc}Data");
        $docType = '';
    }

    return array_map(
      function ($node) use ($data_map, $parseNode, $docType) {
        return $parseNode($node, $data_map, $docType);
      },
      $rootData
    );
  }
}
