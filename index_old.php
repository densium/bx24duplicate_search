<?php
require(__DIR__ . '/libs/crest/CRestPlus.php');
require(__DIR__ . '/libs/debugger/Debugger.php');
define('LOG', 'log_old_script.txt');
define('DOMAIN', 'https://it-solution.bitrix24.ru'); // не забудь на тестах свой портал, в бою портал клиента
define('USER_ID', '1851'); // Пользователь в системе Маляр Юлия

Debugger::writeToLog($_REQUEST, LOG, '$_REQUEST');

#Проверка на то, зупущен ли скрипт автоматически или вручную
if ($_REQUEST['flag'] == 'manual') {
	if ($_REQUEST['ID']) {
		$entityId = $_REQUEST['ID'];

		#Получение всех номеров и email в лиде
		$getLead = CRestPlus::call('crm.lead.get', array('ID' => $entityId));
		Debugger::writeToLog($getLead, LOG, 'getLead');

		foreach ($getLead['result']['PHONE'] as $phone) {
			Debugger::writeToLog($phone, LOG, 'phone');
			$phonesArr[] = substr(preg_replace('~[^0-9]+~', '', $phone['VALUE']), -10);
		}
		Debugger::writeToLog($phonesArr, LOG, 'phonesArr');

		foreach ($getLead['result']['EMAIL'] as $email) {
			$emailsArr[] = $email['VALUE'];
		}
		Debugger::writeToLog($emailsArr, LOG, 'emailsArr');

		$respID = $getLead['result']['ASSIGNED_BY_ID'];
		Debugger::writeToLog($respID, LOG, 'respID');

		$crmTask = 'L_' . $entityId;
		Debugger::writeToLog($crmTask, LOG, 'crmTask');

		//Debugger::debug($phonesArr);
		//Debugger::debug($emailsArr, 1);
	}
} else {
	switch ($_REQUEST['event']) {
		case "ONCRMLEADADD":
			$entityId = $_REQUEST['ID'];
			//$entityId = $_REQUEST['data']['FIELDS']['ID'];
			$getLead = CRestPlus::call('crm.lead.get', array('ID' => $entityId));
			Debugger::writeToLog($getLead, LOG, 'getLead');

			#Получение всех номеров и email в лиде
			foreach ($getLead['result']['PHONE'] as $phone) {
				$phonesArr[] = substr(preg_replace('~[^0-9]+~', '', $phone['VALUE']), -10);
			}
			Debugger::writeToLog($phonesArr, LOG, 'phonesArr');

			foreach ($getLead['result']['EMAIL'] as $email) {
				$emailsArr[] = $email['VALUE'];
			}
			Debugger::writeToLog($emailsArr, LOG, 'emailsArr');

			$respID = $getLead['result']['ASSIGNED_BY_ID'];
			Debugger::writeToLog($respID, LOG, 'respID');

			$crmTask = 'L_' . $entityId;
			Debugger::writeToLog($crmTask, LOG, 'crmTask');

			break;
		case "ONCRMCONTACTADD":
			$entityId = $_REQUEST['ID'];
			//$entityId = $_REQUEST['data']['FIELDS']['ID'];
			#Получение всех номеров и email в контакте
			$getContact = CRestPlus::call('crm.contact.get', array('ID' => $entityId));
			Debugger::writeToLog($getContact, LOG, 'getContact');

			foreach ($getContact['result']['PHONE'] as $phone) {
				$phonesArr[] = substr(preg_replace('~[^0-9]+~', '', $phone['VALUE']), -10);
			}

			Debugger::writeToLog($phonesArr, LOG, 'phonesArr');
			foreach ($getContact['result']['EMAIL'] as $email) {
				$emailsArr[] = $email['VALUE'];
			}			
			Debugger::writeToLog($emailsArr, LOG, 'emailsArr');

			$respID = $getContact['result']['ASSIGNED_BY_ID'];
			Debugger::writeToLog($respID, LOG, 'respID');

			$crmTask = 'C_' . $entityId;
			Debugger::writeToLog($crmTask, LOG, 'crmTask');

			break;
		case "ONCRMCOMPANYADD":
			$entityId = $_REQUEST['ID'];
			//$entityId = $_REQUEST['data']['FIELDS']['ID'];
			#Получение всех номеров и email в компании
			$getCompany = CRestPlus::call('crm.company.get', array('ID' => $entityId));
			Debugger::writeToLog($getCompany, LOG, 'getCompany');

			foreach ($getCompany['result']['PHONE'] as $phone) {
				$phonesArr[] = substr(preg_replace('~[^0-9]+~', '', $phone['VALUE']), -10);
			}
			Debugger::writeToLog($phonesArr, LOG, 'phonesArr');

			foreach ($getCompany['result']['EMAIL'] as $email) {
				$emailsArr[] = $email['VALUE'];
			}
			Debugger::writeToLog($emailsArr, LOG, 'emailsArr');

			$respID = $getCompany['result']['ASSIGNED_BY_ID'];
			Debugger::writeToLog($respID, LOG, 'respID');

			$crmTask = 'CO_' . $entityId;
			Debugger::writeToLog($crmTask, LOG, 'crmTask');

			break;
	}
}

#Добавление маски для проверки всех вариантов (в Б24 7 и 8 - разные номера)
foreach ($phonesArr as $v) {
	$phones[] = $v;
	$phones[] = '7' . $v;
	$phones[] = '+7' . $v;
	$phones[] = '8' . $v;
}
Debugger::writeToLog($phones, LOG, 'phones');

$phones = array_chunk($phones, 20);
Debugger::writeToLog($phones, LOG, 'phones');
$emails = array_chunk($emailsArr, 20);
Debugger::writeToLog($emails, LOG, 'emails');

#Получение дублей с таким же номером
### Выбирает не больше 20 ###
foreach ($phones as $k => $v) {
	$getDuplicates = CRestPlus::call('crm.duplicate.findbycomm', array(
		'type' => 'PHONE',
		'values' => $v,
	));
	Debugger::writeToLog($getDuplicates, LOG, 'getDuplicates');
	foreach ($getDuplicates['result'] as $key => $value) {
		foreach ($value as $v) {
			Debugger::writeToLog($entityId, LOG, 'entityId');
			Debugger::writeToLog($v, LOG, 'v');
			if ($v != $entityId) {
				$list[$key][] = $v;
			}
		}
	}
}
Debugger::writeToLog($list, LOG, 'list');

$nodup['lead'] =    array_unique($list['LEAD']);
$nodup['contact'] = array_unique($list['CONTACT']);
$nodup['company'] = array_unique($list['COMPANY']);
Debugger::writeToLog($nodup, LOG, 'nodup');

if ($nodup['lead']) $lead =       CRestPlus::callBatchList('crm.lead.list', array('filter' => array('ID' => $nodup['lead'])));
if ($nodup['company']) $company = CRestPlus::callBatchList('crm.company.list', array('filter' => array('ID' => $nodup['company'])));
if ($nodup['contact']) $contact = CRestPlus::callBatchList('crm.contact.list', array('filter' => array('ID' => $nodup['contact'])));
Debugger::writeToLog($lead, LOG, 'lead');
Debugger::writeToLog($company, LOG, 'company');
Debugger::writeToLog($contact, LOG, 'contact');

#Получение дублей с таким же email
### Выбирает не больше 20 ###
foreach ($emails as $k => $v) {
	$getDuplicates = CRestPlus::call('crm.duplicate.findbycomm', array(
		'type' => 'EMAIL',
		'values' => $v,
	));
	foreach ($getDuplicates['result'] as $key => $value) {
		foreach ($value as $v) {
			if ($v != $entityId) $listEmail[$key][] = $v;
		}
	}
}
Debugger::writeToLog($listEmail, LOG, 'listEmail');

$nodupEmail['lead'] =    array_unique($listEmail['LEAD']);
$nodupEmail['contact'] = array_unique($listEmail['CONTACT']);
$nodupEmail['company'] = array_unique($listEmail['COMPANY']);
Debugger::writeToLog($nodupEmail, LOG, 'nodupEmail');

if ($nodupEmail['lead']) $leadEmail =       CRestPlus::callBatchList('crm.lead.list', array('filter' => array('ID' => $nodupEmail['lead'])));
if ($nodupEmail['company']) $companyEmail = CRestPlus::callBatchList('crm.company.list', array('filter' => array('ID' => $nodupEmail['company'])));
if ($nodupEmail['contact']) $contactEmail = CRestPlus::callBatchList('crm.contact.list', array('filter' => array('ID' => $nodupEmail['contact'])));
Debugger::writeToLog($leadEmail, LOG, 'leadEmail');
Debugger::writeToLog($companyEmail, LOG, 'companyEmail');
Debugger::writeToLog($contactEmail, LOG, 'contactEmail');

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

#Формирование ссылок на дубликаты
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

#Пост сообщения в ленту
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
						'RESPONSIBLE_ID' => $respID,
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
						'RESPONSIBLE_ID' => $respID,
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
					'RESPONSIBLE_ID' => $respID,
					'DESCRIPTION' => $desc,
					'UF_CRM_TASK' => array($crmTask)
				)));
				Debugger::writeToLog($taskCall, LOG, 'taskCall');
			}
		}
	}
}
