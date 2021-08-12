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

// Получить данные сущности по ID, найти дубликаты
$crmEntity = getCrmEntity($_REQUEST['ID'], $entityTypeId);

$duplicates = [];
$duplicates['phoneList'] = findDuplicates(parsePhones($crmEntity), $crmEntity['result']['ID'], 'PHONE');
$duplicates['emailList'] = findDuplicates(parseEmails($crmEntity), $crmEntity['result']['ID'], 'EMAIL');
$duplicates['titleList'] = findDuplicatesByTitle($crmEntity['result']['TITLE']);
$duplicates['namesList'] = findDuplicatesByNames()

// Указываем какой запрос формировать
if ($crmEntity['result']['LAST_NAME'] and $crmEntity['result']['NAME'] and $crmEntity['result']['SECOND_NAME']) {
	$result = findDuplicatesByNames(
		$crmEntity['result']['LAST_NAME'],
		$crmEntity['result']['NAME'],
		$crmEntity['result']['SECOND_NAME'],
		'NAME, LAST_NAME, SECOND_NAME'
	);
} else if ($crmEntity['result']['LAST_NAME'] and $crmEntity['result']['NAME']) {
	$result = findDuplicatesByNames(
		$crmEntity['result']['LAST_NAME'],
		$crmEntity['result']['NAME'],
		null,
		'NAME, LAST_NAME'
	);
} else if ($crmEntity['result']['NAME']) {
	$result = findDuplicatesByNames(
		$crmEntity['result']['NAME'],
		null,
		null,
		'NAME ONLY'
	);
} else {
	Debugger::writeToLog('Не заданы поля для поиска по имени', LOG, 'Error: Missing parameters');
	exit;
}

// Если найдены дубликаты, то пост в ленту и создание задачи
if ($duplicatesPhoneList and $duplicatesEmailList) {
	$desc = makeDescription($duplicatesPhoneList, 'PHONE') . "\n\n" . makeDescription($duplicatesEmailList, 'EMAIL');
} else if ($duplicatesPhoneList) {
	$desc = makeDescription($duplicatesPhoneList, 'PHONE');
} elseif ($duplicatesEmailList['result']) {
	$desc = makeDescription($duplicatesEmailList, 'EMAIL');
} else {
	Debugger::writeToLog($desc, LOG, 'Дубликаты в базе не обнаружены');
	exit;
}

notifyPost($desc, $_REQUEST['ID'], $entityTypeId);

// if (isset($_REQUEST['flag']) and $_REQUEST['flag'] == 'manual') {
// } else {
// 	postTask($desc);
// }