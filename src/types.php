<?php

return [
  //'purchaseNoticeOA',
  //'purchaseNoticeAE',
  //'purchaseNoticeZK',
  'purchaseNoticeOK' => [
    'created_at' => [
      'type' => 'date',
      'xpath' => 'ns2:createDateTime',
    ],
    'guid' => 'ns2:guid',
    'attachments' => [
      'type' => 'array',
      'xpath' => 'ns2:attachments/document',
      'element' => [
        'guid' => 'guid',
        'created_at' => [
          'type' => 'date',
          'xpath' => 'createdDateTime'
        ]
      ]
    ]
  ]
];
