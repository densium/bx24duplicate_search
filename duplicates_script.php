<?php

define('CRM_HOST', 'poverkapro.bitrix24.ru');
define('CRM_WEBHOOK', 'https://'.CRM_HOST.'/rest/1/oiaath5dok7pcmdv/');

$name = $_POST['name']
$phone = $_POST['phone'];
$email = $_POST['email'];

$entity_type = '';
$entity_id = '';

function findDuplicates ($phone, $email) {
    if ($phone) {
        $url = CRM_WEBHOOK . 'crm.duplicate.findbycomm/';
        $data = array('type' => "PHONE", 'values' => [ $phone ]);
        $result = json_decode(file_get_contents($url . '?' . http_build_query($data)), true);
    
        if (array_key_exists('CONTACT', $result['result'])) {
            $entity_type = '3';
            $entity_id = $result['result']['CONTACT'][0];
        } else if (array_key_exists('COMPANY', $result['result'])) {
            $entity_type = '4';
            $entity_id = $result['result']['COMPANY'][0];
        } else if (array_key_exists('LEAD', $result['result'])) {
            $entity_type = '1';
            $entity_id = $result['result']['LEAD'][0];
        }
    }
    
    if ($email) {
        $url = CRM_WEBHOOK . 'crm.duplicate.findbycomm/';
        $data = array('type' => "EMAIL", 'values' => [ $email ]);
        $result = json_decode(file_get_contents($url . '?' . http_build_query($data)), true);
    
        if (array_key_exists('CONTACT', $result['result'])) {
            $entity_type = '3';
            $entity_id = $result['result']['CONTACT'][0];
        } else if (array_key_exists('COMPANY', $result['result'])) {
            $entity_type = '4';
            $entity_id = $result['result']['COMPANY'][0];
        } else if (array_key_exists('LEAD', $result['result'])) {
            $entity_type = '1';
            $entity_id = $result['result']['LEAD'][0];
        }
    }
}


if (!$entity_id) {
    $url = CRM_WEBHOOK . 'crm.lead.add/';
    $data = array(
        'fields' => array(
            'TITLE' => $name,
            'PHONE' => [array('VALUE' => $phone, 'TYPE' => 'WORK')],
            'EMAIL' => [array('VALUE' => $email, 'TYPE' => 'WORK')]
        )
    );

    $result = json_decode(file_get_contents($url . '?' . http_build_query($data)), true);

    $entity_id = $result['result'];
    $entity_type = '1';
}

$entity_link = 'https://'.CRM_HOST.'/crm/';
if ($entity_type == '1')
    $entity_link = $entity_link . 'lead';
if ($entity_type == '3')
    $entity_link = $entity_link . 'contact';
if ($entity_type == '4')
    $entity_link = $entity_link . 'company';
$entity_link = $entity_link . '/details/' . $entity_id . '/';

$message = '����� ������ �� ������� ' . $entity_link;

$activity_title = '����� ������';

$user_id = 909;

$url = CRM_WEBHOOK . 'crm.activity.add/';
$communications = [array('VALUE'=>'net_kontaktnih_dannih_123456@mail.com', 'ENTITY_ID'=>$entity_id, 'ENTITY_TYPE_ID'=>$entity_type)];
if ($phone)
    $communications = [array('VALUE'=>$phone, 'ENTITY_ID'=>$entity_id, 'ENTITY_TYPE_ID'=>$entity_type)];
if ($email)
    $communications = [array('VALUE'=>$email, 'ENTITY_ID'=>$entity_id, 'ENTITY_TYPE_ID'=>$entity_type)];
$data = array(
    'fields' => array(
        'OWNER_TYPE_ID' => $entity_type,
        'OWNER_ID' => $entity_id,
        'TYPE_ID' => '1',
        'DESCRIPTION' => $message,
        'RESPONSIBLE_ID' => $user_id,
        'SUBJECT' => $activity_title,
        'COMMUNICATIONS' => $communications
    )
);
file_get_contents($url . '?' . http_build_query($data));

$url = CRM_WEBHOOK . 'im.notify/';
$data = array(
    'type' => 'SYSTEM',
    'message' => $message,
    'to' => $user_id,
);
file_get_contents($url . '?' . http_build_query($data));
