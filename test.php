<?php

require(__DIR__.'/vendor/autoload.php');

$crawler = new GZCrawler\Crawler([
  'fileFilter' => [
    'dateFrom'=>new DateTime('2017-03-01'),
    'regions'=>['Moskva'],
    'customFilter'=> function($files) {
      return $files;
    }
  ],
  'zipDone' => function($zip) {
    echo "zip archive done: $zip".PHP_EOL;
  },
  'newItem' => function($item) {
    echo "new item: ".json_encode($item).PHP_EOL;
  }
]);

$crawler->start();
