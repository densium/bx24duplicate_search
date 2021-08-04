<?php

/**
 * TODO Обернуть все в функции  
 * Реально нужно каждой строкой писать writeToLog? Может один writeToLog для всего написать?
 * Посмотреть скрипт Марка, может можно просто написать 1.Получить данные, 2.Проверить дубликаты 3.Выдать данные.
 * Из объекта сущности, которую мы получили, можно вызвать например Debugger::writeToLog?
 */

require(__DIR__ . '/libs/crest/CRestPlus.php');
require(__DIR__ . '/libs/debugger/Debugger.php');
define('LOG', 'log_new.txt');
define('DOMAIN', 'https://it-solution.bitrix24.ru'); // не забудь на тестах свой портал, в бою портал клиента
define('USER_ID', ''); // Пользователь в системе Маляр Юлия

// Проверить передан ли параметр ID сущности, без которого не получится поиск
if (empty($_REQUEST['ID']) or $_REQUEST['ID'] === 0) {
	Debugger::writeToLog('Не задан ID cущности для поиска', LOG, 'Error: Missing parametr ID');
	exit;
} else {
	Debugger::writeToLog($_REQUEST, LOG, '$_REQUEST');
}

// Записать тип сущности если он передан в параметре, если нет, то ориентироваться на события
if (empty($_REQUEST['ENTITY_TYPE_ID'])) {
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

	// $userID = $result['result']['ASSIGNED_BY_ID'];

	//Debugger::writeToLog($userID, LOG, 'userID');
	Debugger::writeToLog($result, LOG, 'result');
	return $result;
}

$crmEntity = getCrmEntity($_REQUEST['ID'], $entityTypeId);

/**
 * TODO Проверить работает ли метод без масок номеров
 */
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
	Debugger::writeToLog($phonesEdited, LOG, 'phonesEdited');
	return $phonesEdited;
}

function parseEmails($entityObject)
{
	$emailsArr = array();

	foreach ($entityObject['result']['EMAIL'] as $email) {
		array_push($emailsArr, $email['VALUE']);
	}

	$emailsArr = array_chunk($emailsArr, 20);
	Debugger::writeToLog($emailsArr, LOG, 'emailsArr');
	return $emailsArr;
}

$crmEntityEmailsArr = parseEmails($crmEntity);
$crmEntityPhonesArr = parsePhones($crmEntity);

#Получение дублей с таким же номером
### Выбирает не больше 20 ###
function findDuplicatesByPhone($phonesArr, $entityId)
{
	$getDuplicates = CRestPlus::call('crm.duplicate.findbycomm', array('type' => 'PHONE', 'values' => $phonesArr[0]));
	Debugger::writeToLog($getDuplicates, LOG, 'getDuplicates');

	$list = array();

	foreach ($getDuplicates['result'] as $key => $value) {
		foreach ($value as $id) {
			if ($id != $entityId) {
				$list[$key][] = $id;
			}
		}
	}
	Debugger::writeToLog($list, LOG, 'list');
    
	// $list['LEAD'];
	// $list['CONTACT'];
	// $list['COMPANY'];

	// if ($nodup['lead']) $lead =       CRestPlus::callBatchList('crm.lead.list', array('filter' => array('ID' => $nodup['lead'])));
	// if ($nodup['company']) $company = CRestPlus::callBatchList('crm.company.list', array('filter' => array('ID' => $nodup['company'])));
	// if ($nodup['contact']) $contact = CRestPlus::callBatchList('crm.contact.list', array('filter' => array('ID' => $nodup['contact'])));
}

#Получение дублей с таким же email
### Выбирает не больше 20 ###
function findDuplicatesByEmail($emailsArr, $entityId)
{
	$getDuplicates = CRestPlus::call('crm.duplicate.findbycomm', array('type' => 'EMAIL', 'values' => $emailsArr[0]));
	Debugger::writeToLog($getDuplicates, LOG, 'getDuplicates');
	
	$listEmail = array();

	foreach ($getDuplicates['result'] as $key => $value) {
		foreach ($value as $id) {
			if ($id != $entityId) {
				$listEmail[$key][] = $id;
			}
		}
	}
	Debugger::writeToLog($listEmail, LOG, 'listEmail');
	
	// $listEmail['LEAD'];
	// $listEmail['CONTACT'];
	// $listEmail['COMPANY'];
	
	// if ($nodupEmail['lead']) $leadEmail =       CRestPlus::callBatchList('crm.lead.list', array('filter' => array('ID' => $nodupEmail['lead'])));
	// if ($nodupEmail['company']) $companyEmail = CRestPlus::callBatchList('crm.company.list', array('filter' => array('ID' => $nodupEmail['company'])));
	// if ($nodupEmail['contact']) $contactEmail = CRestPlus::callBatchList('crm.contact.list', array('filter' => array('ID' => $nodupEmail['contact'])));
}

$duplicatesList[] = findDuplicatesByPhone($crmEntityPhonesArr, $crmEntity['result']['ID']);
// $duplicatesList[] = findDuplicatesByEmail($crmEntityEmailsArr, $crmEntity['result']['ID']);
// Debugger::writeToLog($duplicatesList, LOG, 'duplicatesList');

// Формирование текста
function makeFinalTextMessage() 
{
	$i = 0;
	foreach ($contact['result']['result'] as $sec) {
		foreach ($sec as $value) {
			$final['contact'][$i]['ID'] = $value['ID'];
			$final['contact'][$i]['TITLE'] = 'Контакт: ' . $value['NAME'] . ' ' . $value['LAST_NAME'];
			$i++;
		}
	}
	
	$i = 0;
	foreach ($company['result']['result'] as $sec) {
		foreach ($sec as $value) {
			$final['company'][$i]['ID'] = $value['ID'];
			$final['company'][$i]['TITLE'] = 'Компания: ' . $value['TITLE'];
			$i++;
		}
	}
	
	$i = 0;
	foreach ($lead['result']['result'] as $sec) {
		foreach ($sec as $value) {
			$final['lead'][$i]['ID'] = $value['ID'];
			$final['lead'][$i]['TITLE'] = 'Лид: ' . $value['TITLE'];
			$i++;
		}
	}
	
	$i = 0;
	foreach ($contactEmail['result']['result'] as $sec) {
		foreach ($sec as $value) {
			$finalEmail['contact'][$i]['ID'] = $value['ID'];
			$finalEmail['contact'][$i]['TITLE'] = 'Контакт: ' . $value['NAME'] . ' ' . $value['LAST_NAME'];
			$i++;
		}
	}
	
	$i = 0;
	foreach ($companyEmail['result']['result'] as $sec) {
		foreach ($sec as $value) {
			$finalEmail['company'][$i]['ID'] = $value['ID'];
			$finalEmail['company'][$i]['TITLE'] = 'Компания: ' . $value['TITLE'];
			$i++;
		}
	}
	
	$i = 0;
	foreach ($leadEmail['result']['result'] as $sec) {
		foreach ($sec as $value) {
			$finalEmail['lead'][$i]['ID'] = $value['ID'];
			$finalEmail['lead'][$i]['TITLE'] = 'Лид: ' . $value['TITLE'];
			$i++;
		}
	}
	Debugger::writeToLog($final, LOG, 'final');
	Debugger::writeToLog($finalEmail, LOG, 'finalEmail');
}

// Формирование ссылок на дубликаты
function getEntityLinks($entityObject)
{
	foreach ($final as $key => $data) {
		foreach ($data as $item) {
			$taskDesc .= "\n[url=" . DOMAIN . "/crm/" . strtolower($key) . "/details/" . $item['ID'] . "/]" . $item['TITLE'] . "[/url]";
		}
	}
	foreach ($finalEmail as $key => $data) {
		foreach ($data as $item) {
			$taskDescEmail .= "\n[url=" . DOMAIN . "/crm/" . strtolower($key) . "/details/" . $item['ID'] . "/]" . $item['TITLE'] . "[/url]";
		}
	}

	$taskDesc = ($final) ? "Дубликаты по номеру телефона:" . $taskDesc : "";
	$taskDescEmail = ($finalEmail) ? "\n\nДубликаты по email:" . $taskDescEmail : "";

	Debugger::writeToLog($taskDesc, LOG, 'taskDesc');
	Debugger::writeToLog($taskDescEmail, LOG, 'taskDescEmail');
}

// Пост сообщения в ленту
function makeNotifyPost($postText, $exception)
{
	if ($_REQUEST['flag'] == 'manual') {
		if ($_REQUEST['ID']) {
			$ei = $_REQUEST['ID'];
			$eti = '1';
		} elseif ($_REQUEST['contactID']) {
			$ei = $_REQUEST['contactID'];
			$eti = '3';
		} elseif ($_REQUEST['companyID']) {
			$ei = $_REQUEST['companyID'];
			$eti = '4';
		}
		Debugger::writeToLog($ei, LOG, 'ei');
		Debugger::writeToLog($eti, LOG, 'eti');

		$desc = ((!$final) && (!$finalEmail)) ? 'Дубликаты в базе не обнаружены' : $taskDesc . $taskDescEmail;
		Debugger::writeToLog($desc, LOG, 'desc');

		$askdlq = CRestPlus::call('crm.livefeedmessage.add', array('fields' => array(
			'POST_TITLE' => 'Найден дубликат в базе клиентов',
			'MESSAGE' => $desc,
			'ENTITYTYPEID' => $eti,
			'ENTITYID' => $ei
		)));

		Debugger::writeToLog($askdlq, LOG, 'askdlq');
	} else {
		Debugger::writeToLog($getLead, 'gurlog.txt', 'getLead');
		if (!empty($getLead['result']['COMPANY_ID'])) {
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
		}
		if (!empty($getLead['result']['CONTACT_ID'])) {
			foreach ($final['contact'] as $fCValue) {
				if ($getLead['result']['CONTACT_ID'] != $fCValue['ID']) {
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
		}
		foreach ($final['lead'] as $fLValue) {
			if ($entityId != $fLValue['ID']) {
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
	}
}
