<?php

// 1.Получить данные, 2.Проверить дубликаты 3.Выдать данные.

require(__DIR__ . '/libs/crest/CRestPlus.php');
require(__DIR__ . '/libs/debugger/Debugger.php');
define('LOG', 'log_new.txt');
define('DOMAIN', 'https://it-solution.bitrix24.ru'); // не забудь на тестах свой портал, в бою портал клиента
define('USER_ID', ''); // Пользователь в системе Маляр Юлия

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
	Debugger::writeToLog($getDuplicates, LOG, 'getDuplicates');

	$list = array();

	foreach ($getDuplicates['result'] as $key => $value) {
		foreach ($value as $id) {
			if ($id != $entityId) {
				$list[$key][] = $id;
			}
		}
	}
	Debugger::writeToLog($list, LOG, 'findDuplicates / тип дубликатов - ' . $typeOfDuplicates);
	return $list;
}

// TODO - Написать эту функцию
// При этом при поиске по Имени, Фамилии и Отчеству надо учесть, что могут быть перепутаны они местами, например Имя указано в поле Фамилия и наоборот.
function findDuplicatesByName() 
{
	return null;
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
	if ($duplicatesArr['LEAD']) $lead =       CRestPlus::call('crm.lead.list', array('filter' => array('ID' => $duplicatesArr['lead'])));
	if ($duplicatesArr['COMPANY']) $company = CRestPlus::call('crm.company.list', array('filter' => array('ID' => $duplicatesArr['company'])));
	if ($duplicatesArr['CONTACT']) $contact = CRestPlus::call('crm.contact.list', array('filter' => array('ID' => $duplicatesArr['contact'])));

	$i = 0;
	if ($lead['result']['result']) {
		foreach ($lead['result']['result'] as $sec) {
			foreach ($sec as $value) {
				$final['lead'][$i]['ID'] = $value['ID'];
				$final['lead'][$i]['TITLE'] = 'Лид: ' . $value['TITLE'];
				$i++;
			}
		}		
	}
	
	$i = 0;
	if ($contact['result']['result']) {
		foreach ($contact['result']['result'] as $sec) {
			foreach ($sec as $value) {
				$final['contact'][$i]['ID'] = $value['ID'];
				$final['contact'][$i]['TITLE'] = 'Контакт: ' . $value['NAME'] . ' ' . $value['LAST_NAME'];
				$i++;
			}
		}
	}
	
	$i = 0;
	if ($company['result']['result']) {
		foreach ($company['result']['result'] as $sec) {
			foreach ($sec as $value) {
				$final['company'][$i]['ID'] = $value['ID'];
				$final['company'][$i]['TITLE'] = 'Компания: ' . $value['TITLE'];
				$i++;
			}
		}
	}
	Debugger::writeToLog($final, LOG, 'final');
	
	foreach ($final as $key => $data) {
		foreach ($data as $item) {
			$taskDesc .= "\n[url=" . DOMAIN . "/crm/" . strtolower($key) . "/details/" . $item['ID'] . "/]" . $item['TITLE'] . "[/url]";
		}
	}
	Debugger::writeToLog($taskDesc, LOG, 'taskDesc');
	
	switch ($typeOfDuplicates) {
		case 'EMAIL':
			$taskDesc = "Дубликаты по номеру телефона:" . $taskDesc;
			break;
			
			case 'PHONE':
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
		'POST_TITLE' => 'Найден дубликат в базе клиентов',
		'MESSAGE' => $postText,
		'ENTITYTYPEID' => $entityTypeId,
		'ENTITYID' => $entityId
	)));
	Debugger::writeToLog($postId, LOG, 'notifyPost');
}

// Создать задачи пользователю
function postTask($taskDescription) 
{
	foreach ($final['lead'] as $fLValue) {
		if ($entityId != $fLValue['ID']) {
			#Постановка задачи
			if ($taskDesc) {
				$taskCall = CRestPlus::call('tasks.task.add', array('fields' => array(
					'TITLE' => 'Найден дубликат в базе клиентов',
					'RESPONSIBLE_ID' => $userID,
					'DESCRIPTION' => $desc,
					'UF_CRM_TASK' => array($crmTask)
				)));
				Debugger::writeToLog($taskCall, LOG, 'taskCall');
			}
		}
	}

	foreach ($final['company'] as $fCoValue) {
		if ($getLead['result']['COMPANY_ID'] != $fCoValue['ID']) {
			#Постановка задачи
			if ($taskDesc) {
				$desc = ((!$final) && (!$finalEmail)) ? 'Дубликаты в базе не обнаружены' : $taskDesc . $taskDescEmail;
				Debugger::writeToLog($desc, LOG, 'desc');
				$taskCall = CRestPlus::call('tasks.task.add', array('fields' => array(
					'TITLE' => 'Найден дубликат в базе клиентов',
					'RESPONSIBLE_ID' => $userID,
					'DESCRIPTION' => $desc,
					'UF_CRM_TASK' => array($crmTask)
				)));
				Debugger::writeToLog($taskCall, LOG, 'taskCall');
			}
		}
	}
	
	foreach ($final['contact'] as $fCValue) {
		if ($getLead['result']['CONTACT_ID'] != $fCValue['ID']) {

				$desc = ((!$final) && (!$finalEmail)) ? 'Дубликаты в базе не обнаружены' : $taskDesc . $taskDescEmail;
				Debugger::writeToLog($desc, LOG, 'desc');
				$taskCall = CRestPlus::call('tasks.task.add', array('fields' => array(
					'TITLE' => 'Найден дубликат в базе клиентов',
					'RESPONSIBLE_ID' => $userID,
					'DESCRIPTION' => $desc,
					'UF_CRM_TASK' => array($crmTask)
				)));
				Debugger::writeToLog($taskCall, LOG, 'taskCall');
			}
		}
}	

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
if ($duplicatesPhoneList['result'] and $duplicatesEmailList['result']) {
	$desc = makeDescription($duplicatesPhoneList, 'PHONE') . "\n\n" . makeDescription($duplicatesEmailList, 'EMAIL');
} else if ($duplicatesPhoneList['result']) {
	$desc = makeDescription($duplicatesPhoneList, 'PHONE');
} elseif ($duplicatesEmailList['result']) {
	$desc = makeDescription($duplicatesEmailList, 'EMAIL');
} else {
	Debugger::writeToLog($desc, LOG, 'Дубликаты в базе не обнаружены');
	exit;
}

notifyPost($desc, $_REQUEST['ID'], $entityTypeId);
postTask($desc);

// ---!
	
?>