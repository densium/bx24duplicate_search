<?php

// 1.Получить данные, 2.Проверить дубликаты 3.Выдать данные.

require(__DIR__ . '/libs/crest/CRestPlus.php');
require(__DIR__ . '/libs/debugger/Debugger.php');
define('LOG', 'log_new.txt');
define('DOMAIN', 'https://it-solution.bitrix24.ru'); // не забудь на тестах свой портал, в бою портал клиента
define('USER_ID', '1851'); // Пользователь в системе Маляр Юлия

/**
 * Получить объект сущности Лид, Контакт, Компания
 * @param integer $entityId ID cущности по которой будут искаться дубликаты
 * @param integer $entityTypeId тип сущности: 3 - Контакт, 4 - Компания, 1 - Лид
 * @var integer $userID ID Пользователя, который получит уведомление
 */
function getCrmEntity($entityId, $entityTypeId)
{
	$requestParams = array('ID' => $entityId);

	switch ($entityTypeId) {
		case "1":
			$result = CRestPlus::call('crm.lead.get', $requestParams);
			//$crmTask = 'L_' . $entityId;
			break;

		case "3":
			$result = CRestPlus::call('crm.contact.get', $requestParams);
			// $crmTask = 'C_' . $entityId;			
			break;

		case "4":
			$result = CRestPlus::call('crm.company.get', $requestParams);
			// $crmTask = 'CO_' . $entityId;
			break;
	}

	//$userID = $result['result']['ASSIGNED_BY_ID'];
	//Debugger::writeToLog($userID, LOG, 'userID');

	Debugger::writeToLog($result, LOG, 'getCrmEntity');
	return $result;
}

// Вытаскивает номера в массив и добавляет маски 9,+7,8
function parsePhones($entityObject)
{
	$phonesArr = array();
	
	foreach ($entityObject['result']['PHONE'] as $phone) {
		array_push($phonesArr, substr(preg_replace('~[^0-9]+~', '', $phone['VALUE']), -10));
	}
	
	Debugger::writeToLog($phonesArr, LOG, 'phonesArr');
	
	// Добавление маски для проверки всех вариантов (в Б24 7 и 8 - разные номера)
	foreach ($phonesArr as $phone) {
		$phonesEdited[] = $phone;
		$phonesEdited[] = '7' . $phone;
		$phonesEdited[] = '+7' . $phone;
		$phonesEdited[] = '8' . $phone;
	}
	
	$phonesEdited = array_chunk($phonesEdited, 20);
	Debugger::writeToLog($phonesEdited, LOG, 'parsePhones');
	return $phonesEdited;
}

// Вытаскивает почты в массив
function parseEmails($entityObject)
{
	$emailsArr = array();

	foreach ($entityObject['result']['EMAIL'] as $email) {
		array_push($emailsArr, $email['VALUE']);
	}

	$emailsArr = array_chunk($emailsArr, 20);
	Debugger::writeToLog($emailsArr, LOG, 'parseEmails');
	return $emailsArr;
}

/**
 * Получение дублей с таким же номером телефона или почты, выбирает не больше 20
 * @param integer $entityId Исключить из запроса сущность по которой ищем
 * @param string $typeOfDuplicates EMAIL - почты, PHONE - телефоны
 */
function findDuplicates($phonesArr, $entityId, $typeOfDuplicates)
{
	$getDuplicates = CRestPlus::call('crm.duplicate.findbycomm', array('type' => $typeOfDuplicates, 'values' => $phonesArr[0]));
	Debugger::writeToLog($getDuplicates, LOG, 'getDuplicates / typeOfDuplicates - ' . $typeOfDuplicates);

	$list = array();

	foreach ($getDuplicates['result'] as $key => $value) {
		foreach ($value as $id) {
			if ($id != $entityId) {
				$list[$key][] = $id;
			}
		}
	}
	Debugger::writeToLog($list, LOG, 'findDuplicates / typeOfDuplicates - ' . $typeOfDuplicates);
	return $list;
}

// TODO - Написать эту функцию
// При этом при поиске по Имени, Фамилии и Отчеству надо учесть, что могут быть перепутаны они местами, например Имя указано в поле Фамилия и наоборот.
function findDuplicatesByName($entityId, $name, $lastName, $secondName) 
{
	/*
	Получить контакты и лиды с именем, фамилией и отчеством
	Какие есть варианты:
	1. 2а варианта входящих данных первая буква низкая, вторая высокая
	2. Проверять наличие аргументов
	2. Прямой поиск имя = имя, фамилия = фамилия и так далее
	3. Обратный имя = фамилия и фамилия = имя
	4. 
	*/
	return null;
}

function findDuplicatesByTitle($crmEntityTitle) {
	// Получить лиды, сделки, компании по названию
	// Искать часть текущей строки в названии

	$requestParams = array('filter' => array('TITLE' => $crmEntityTitle), 'select' => array('ID', 'TITLE'));
	
	// Какой там оператор для "содержит"
	$requestParamsSubstring = array('filter' => array('%TITLE' => $crmEntityTitle), 'select' => array('ID', 'TITLE'));

	$result['lead'] = CRestPlus::call('crm.lead.list', $requestParams);
	$result['company'] = CRestPlus::call('crm.company.list', $requestParams);
	$result['contact'] = CRestPlus::call('crm.contact.list', $requestParams);
	
	$result['lead'][] = CRestPlus::call('crm.lead.list', $requestParamsSubstring);
	$result['company'][] = CRestPlus::call('crm.company.list', $requestParamsSubstring);
	$result['contact'][] = CRestPlus::call('crm.contact.list', $requestParamsSubstring);

	return $result;	
}

// TODO - Написать эту функцию
// При этом при поиске по Названию лида, Названию компании, Instagram аккаунту или Instagram ссылки – важно чтобы поиск осуществлялся не по 100% совпадению,
// а по сходным словам. Например Instagram аккаунт у нас может быть  записан то со знаком @ то без этого знака.
function findDuplicatesByInstagram()
{
	return null;
}

/**
 * Получает массив дубликатов и возвращает описание задачи по ним.
 * @param string $typeOfDuplicates email - почты, phone - телефоны
 */
function makeDescription($duplicatesArr, $typeOfDuplicates)
{

	Debugger::writeToLog($duplicatesArr, LOG, 'duplicatesArr');

	if ($duplicatesArr[0]['LEAD']) $lead =       CRestPlus::call('crm.lead.list', array('filter' => array('ID' => $duplicatesArr[0]['LEAD'])));
	if ($duplicatesArr[0]['COMPANY']) $company = CRestPlus::call('crm.company.list', array('filter' => array('ID' => $duplicatesArr[0]['COMPANY'])));
	if ($duplicatesArr[0]['CONTACT']) $contact = CRestPlus::call('crm.contact.list', array('filter' => array('ID' => $duplicatesArr[0]['CONTACT'])));

	Debugger::writeToLog($lead, LOG, 'lead list');
	Debugger::writeToLog($company, LOG, 'company list');
	Debugger::writeToLog($contact, LOG, 'contact list');

	$final = array();

	// TODO - Переписать в функцию
	$i = 0;
	if ($lead['result']) {
		foreach ($lead['result'] as $value) {
			$final['lead'][$i]['ID'] = $value['ID'];
			$final['lead'][$i]['TITLE'] = 'Лид: ' . $value['TITLE'];
			$i++;
		}
	}
	
	$i = 0;
	if ($contact['result']) {
		foreach ($contact['result'] as $value) {
				$final['contact'][$i]['ID'] = $value['ID'];
				$final['contact'][$i]['TITLE'] = 'Контакт: ' . $value['NAME'] . ' ' . $value['LAST_NAME'];
				$i++;
		}
	}
	
	$i = 0;
	if ($company['result']) {
		foreach ($company['result'] as $value) {
				$final['company'][$i]['ID'] = $value['ID'];
				$final['company'][$i]['TITLE'] = 'Компания: ' . $value['TITLE'];
				$i++;
		}
	}
	Debugger::writeToLog($final, LOG, 'final');
	
	$taskDesc = null;

	foreach ($final as $key => $data) {
		foreach ($data as $item) {
			$taskDesc .= "\n[url=" . DOMAIN . "/crm/" . strtolower($key) . "/details/" . $item['ID'] . "/]" . $item['TITLE'] . "[/url]";
		}
	}
	Debugger::writeToLog($taskDesc, LOG, 'taskDesc');
	
	switch ($typeOfDuplicates) {
		case 'PHONE':
			$taskDesc = "Дубликаты по номеру телефона:" . $taskDesc;
			break;
			
		case 'EMAIL':
			$taskDesc = "Дубликаты по email:" . $taskDesc;
			break;
	}
			
	Debugger::writeToLog($taskDesc, LOG, 'taskDesc / тип дубликатов - ' . $typeOfDuplicates);
	return $taskDesc;
}

// Пост сообщения в ленту
function notifyPost($postText, $entityId, $entityTypeId)
{
	$postId = CRestPlus::call('crm.livefeedmessage.add', array('fields' => array(
		'POST_TITLE' => 'Найдены дубликаты в базе клиентов',
		'MESSAGE' => $postText,
		'ENTITYTYPEID' => $entityTypeId,
		'ENTITYID' => $entityId
	)));
	Debugger::writeToLog($postId, LOG, 'notifyPost');
}

// Создать задачи пользователю


// !--- Выполнение скрипта

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

$duplicatesPhoneList[] = findDuplicates(parsePhones($crmEntity), $crmEntity['result']['ID'], 'PHONE');
$duplicatesEmailList[] = findDuplicates(parseEmails($crmEntity), $crmEntity['result']['ID'], 'EMAIL');

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

// ---!
