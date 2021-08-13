<?php
require_once __DIR__ . '/search_functions.php';
require_once __DIR__ . '/libs/debugger/Debugger.php';

// 1.Получить данные, 2.Проверить дубликаты 3.Выдать данные.

// Проверить передан ли параметр ID сущности, без которого не получится поиск
if (empty($_REQUEST['ID']) or $_REQUEST['ID'] === 0) 
{
	Debugger::writeToLog('Не задан ID cущности для поиска', LOG, 'Error: Missing parametr ID');
	exit;
} else {
	Debugger::writeToLog($_REQUEST, LOG, '$_REQUEST');
}

// Записать тип сущности если он передан в параметре, если нет, то ориентироваться на события
if (empty($_REQUEST['ENTITY_TYPE_ID']))
{
	switch ($_REQUEST['event']) {
		case 'ONCRMLEADADD':
			$entityTypeId = 1;
			break;
		case 'ONCRMCONTACTADD':
			$entityTypeId = 3;
			break;
		case 'ONCRMCOMPANYADD':
			$entityTypeId = 3;
			break;
	}
} else {
	$entityTypeId = $_REQUEST['ENTITY_TYPE_ID'];
}

// Получить данные сущности по ID
$crmEntity = getCrmEntity($_REQUEST['ID'], $entityTypeId);

// Найти дубликаты
$duplicates = [];

$try = findDuplicatesByTitle($crmEntity['TITLE'], $crmEntity['ID']);
isset($try) ? $duplicates['titleList'] = $try : null;

if ($crmEntity['PHONE']) {
    $try = findDuplicates(parsePhones($crmEntity), $crmEntity['ID'], 'PHONE');
    isset($try) ? $duplicates['phoneList'] = $try : null; 
}

if ($crmEntity['EMAIL']) {
    $try = findDuplicates(parseEmails($crmEntity), $crmEntity['ID'], 'EMAIL');
    isset($try) ? $duplicates['emailList'] = $try : null; 
}

$try = findDuplicatesByNames(checkNames($crmEntity), $crmEntity['ID']); 
isset($try) ? $duplicates['namesList'] = $try : null; 

// Проверить найдены ли дубликаты
if (empty($duplicates)) {
	Debugger::writeToLog($desc, LOG, 'Дубликаты в базе не обнаружены');
	exit;
}

// Если найдены дубликаты, составить описание задачи и поста
if ($duplicates['titleList']) {
    $desc = makeDescription($duplicates['titleList'], 'TITLE');
}
if ($duplicates['phoneList']) {
    $desc .= "\n" . makeDescription($duplicates['phoneList'], 'PHONE');
}
if ($duplicates['emailList']) {
    $desc .= "\n" . makeDescription($duplicates['emailList'], 'EMAIL');
}
if ($duplicates['namesList']) {
    $desc .= "\n" . makeDescription($duplicates['namesList'], 'NAME');
}

// Создать пост в ленту или задачу
if (isset($_REQUEST['flag'])) {
    $_REQUEST['flag'] == 'manual' ? notifyPost($desc, $crmEntity['ID'], $crmEntity['TITLE'], $entityTypeId) : Debugger::writeToLog($_REQUEST['flag'], LOG, 'Request Param != flag');
} else {
    postTask($desc, $crmEntity['ID'], $crmEntity['TITLE'], $entityTypeId);
}