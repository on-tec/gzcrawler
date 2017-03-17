<?php

$base = [
  'id'=> 'ns2:registrationNumber',
  'url' => 'ns2:urlOOS',
  'created' => [
    'type' => 'date',
    'xpath' => 'ns2:createDateTime',
  ],
  'price' => 'ns2:lots/lot/lotData/initialSum',
  'name' => 'ns2:name',
  'ogrn' => 'ns2:customer/mainInfo/ogrn',
  'customer_name' => 'ns2:customer/mainInfo/fullName',
  'customer_shortname' => 'ns2:customer/mainInfo/shortName',
  'customer_address' => 'ns2:customer/mainInfo/legalAddress',
  'customer_postal_address' => 'ns2:customer/mainInfo/postalAddress',
  'customer_phone' => 'ns2:customer/mainInfo/phone',
  'customer_email' => 'ns2:contact/email',
  'contact_firstname' => 'ns2:contact/firstName',
  'contact_lastname' => 'ns2:contact/lastName',
  'contact_middlename' => 'ns2:contact/middleName',
  'submission_close_date' => [
    'type' => 'date',
    'xpath' => 'ns2:submissionCloseDateTime',
  ],
  'attachments' => [
    'type' => 'array',
    'xpath' => 'ns2:attachments/document',
    'element' => [
      'name'=> 'fileName',
      'descriptions' => 'description',
      'url' => 'url'
    ]
  ],
  'lots' => [
    'type' => 'array',
    'xpath' => 'ns2:lots/lot/lotData/lotItems/lotItem',
    'element' => [
      'info' => 'additionalInfo',
      'okpd2' => 'okpd2/code',
      'okpd2_description' => 'okpd2/name'
    ]
  ]
];
return [
  'purchaseNoticeOA' => $base,
  'purchaseNoticeAE' => $base,
  'purchaseNoticeZK' => $base,
  'purchaseNoticeOK' => $base,
  'purchaseProtocolRZOA' => $base,
  'purchaseProtocolPAOA' => $base
];


