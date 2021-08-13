<?php
require_once __DIR__ . '/libs/crest/CRestPlus.php';
require_once __DIR__ . '/libs/debugger/Debugger.php';

define('LOG', 'log_new.txt');
define('REQUEST_LOG', 'log_request.txt');
define('DOMAIN', 'https://it-solution.bitrix24.ru'); // не забудь на тестах свой портал, в бою портал клиента
define('USER_ID', '1851'); // Пользователь в системе Маляр Юлия

// Меняет элементы массива местами
function arrSwap($arr, $elNum1, $elNum2)
{
	$temp = $arr[$elNum1];
	$arr[$elNum1] = $arr[$elNum2];
	$arr[$elNum2] = $temp;
	return $arr;
}

// Вытаскивает номера в массив и добавляет маски 9,+7,8
function parsePhones($entityObject)
{
	$phonesArr = array();

	foreach ($entityObject['PHONE'] as $phone) {
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

	foreach ($entityObject['EMAIL'] as $email) {
		array_push($emailsArr, $email['VALUE']);
	}

	$emailsArr = array_chunk($emailsArr, 20);
	Debugger::writeToLog($emailsArr, LOG, 'parseEmails');
	return $emailsArr;
}

/**
 * Создает читаемый для функции makeDescription массив Id элементов
 * @param integer $entityId ID элемента который нужно исключить из массива
 */
function makeIdsArray($resultArr, $entityId)
{
	foreach ($resultArr as $key => $value) {
		foreach ($value as $id) {
			if ($id['ID'] != $entityId) {
				$list[$key][] = $id['ID'];
			}
		}
	}
	Debugger::writeToLog($list, LOG, 'makeIdsArray');
	return $list ?? null;
}

function checkIdsArray($resultArr, $entityId)
{
	foreach ($resultArr as $key => $value) {
		foreach ($value as $id) {
			if ($id != $entityId) {
				$list[$key][] = $id;
			}
		}
	}
	Debugger::writeToLog($list, LOG, 'checkIdsArray');
	return $list ?? null;
}
/**
 * Получить объект сущности Лид, Контакт, Компания
 * @param integer $entityId ID cущности по которой будут искаться дубликаты
 * @param integer $entityTypeId тип сущности: 3 - Контакт, 4 - Компания, 1 - Лид
 * @var integer $userID ID Пользователя, который будет получитать уведомления - пока выключено
 */
function getCrmEntity($entityId, $entityTypeId)
{
	$requestParams = array('ID' => $entityId);

	switch ($entityTypeId) {
		case "1":
			$result = CRestPlus::call('crm.lead.get', $requestParams);
			Debugger::writeToLog($result, REQUEST_LOG, 'crm.lead.get');
			break;

		case "3":
			$result = CRestPlus::call('crm.contact.get', $requestParams);
			Debugger::writeToLog($result, REQUEST_LOG, 'crm.contact.get');			
			break;

		case "4":
			$result = CRestPlus::call('crm.company.get', $requestParams); 
			Debugger::writeToLog($result, REQUEST_LOG, 'crm.company.get');
			break;
	}
	//$userID = $result['result']['ASSIGNED_BY_ID'];
	//Debugger::writeToLog($userID, LOG, 'userID');
	return $result['result'] ?? null;
}

/**
 * Получение дублей с таким же номером телефона или почты, выбирает не больше 20
 * @param integer $entityId Исключить из запроса сущность по которой ищем
 * @param string $typeOfDuplicates EMAIL - почты, PHONE - телефоны
 */
function findDuplicates($phonesArr, $entityId, $typeOfDuplicates)
{
	$try = CRestPlus::call('crm.duplicate.findbycomm', array('type' => $typeOfDuplicates, 'values' => $phonesArr[0]));
	Debugger::writeToLog($try, REQUEST_LOG, 'crm.duplicate.findbycomm / typeOfDuplicates - ' . $typeOfDuplicates);
	// Debugger::writeToLog($list, LOG, 'findDuplicates / typeOfDuplicates - ' . $typeOfDuplicates);
	return isset($try['result']) ? checkIdsArray($try['result'], $entityId) : null;
}

/**
 * Получить дубли по названию TITLE
 * @param array $crmEntityTitle название crm сущность 
 */
function findDuplicatesByTitle($crmEntityTitle, $entityId)
{
	$requestParamsSubstr = array('filter' => array('%TITLE' => $crmEntityTitle), 'select' => array('ID'));
	// 'select' => array('ID', 'TITLE')

	$requestParamsSubstrContact = array('filter' => array('%NAME' => $crmEntityTitle), 'select' => array('ID'));
	// 'select' => array('ID', 'NAME', 'LAST_NAME')

	$try = CRestPlus::call('crm.lead.list', $requestParamsSubstr); 
	Debugger::writeToLog($try, REQUEST_LOG, 'crm.lead.list');	
	$result['LEAD'] = $try['result'] ?? null;
	
	$try = CRestPlus::call('crm.company.list', $requestParamsSubstr);
	Debugger::writeToLog($try, REQUEST_LOG, 'crm.company.list');	
	$result['COMPANY'] = $try['result'] ?? null;
	
	$try = CRestPlus::call('crm.contact.list', $requestParamsSubstrContact);
	Debugger::writeToLog($try, REQUEST_LOG, 'crm.contact.list');	
	$result['CONTACT'] = $try['result'] ?? null;

	return makeIdsArray($result, $entityId);
}

/**
 * Получить контакты,лиды и компании со схожим именем, фамилией или отчеством
 * Нюансы:
 * Нужно проверять наличие аргументов перед запуском и указывать тип поиска
 * @param string $name из get запроса NAME
 * @param string $lastName из get запроса LAST_NAME
 * @param string $secondName из get запроса SECOND_NAME
 * @param string $searchVariant NAME, LAST_NAME, SECOND_NAME / NAME, LAST_NAME / NAME ONLY
 */
function findDuplicatesByNames($namesObj, $entityId)
{
	if ($namesObj === 'error') {
		Debugger::writeToLog('Не заданы поля для поиска по имени', LOG, 'findDuplicatesByNames');
		return null;
	}

	$lastName = $namesObj[0];
	$name = $namesObj[1];
	$secondName = $namesObj[2];
	$searchVariant = $namesObj[3];

	$selectContactLead = array('ID');
	// $selectContactLead = array('ID', 'NAME', 'LAST_NAME');
	$selectCompany = array('ID');
	// $selectCompany = array('ID', 'TITLE');

	// !--- Формируем запросы
	switch ($searchVariant) {
		case 'NAME, LAST_NAME, SECOND_NAME':
			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
					'%LAST_NAME' => $lastName,
					'%SECOND_NAME' => $secondName
				),
				'select' => $selectContactLead
			);

			$requestParamsReverse = array(
				'filter' => array(
					'%NAME' => $lastName,
					'%LAST_NAME' => $name,
					'%SECOND_NAME' => $secondName
				),
				'select' => $selectContactLead
			);

			// поиск внутри имени контакта и в названии компании
			$arrNames = array($lastName, $name, $secondName);

			$requestNames[] = $arrNames;
			$requestNames[] = arrSwap($arrNames, 0, 1);
			$requestNames[] = arrSwap(arrSwap($arrNames, 0, 1), 1, 2);
			$requestNames[] = arrSwap($arrNames, 1, 2);
			$requestNames[] = arrSwap($arrNames, 2, 0);
			$requestNames[] = arrSwap(arrSwap($arrNames, 2, 0), 1, 2);

			foreach ($requestNames as $key) {
				$requestParamsContact[] = array(
					'filter' => array(
						'NAME' => implode(" ", $key)
					),
					'select' => $selectContactLead
				);
			}

			foreach ($requestNames as $key) {
				$requestParamsCompany[] = array(
					'filter' => array(
						'TITLE' => implode(" ", $key)
					),
					'select' => $selectCompany
				);
			}
			break;

		case 'NAME, LAST_NAME':
			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
					'%LAST_NAME' => $lastName
				),
				'select' => $selectContactLead
			);

			$requestParamsReverse = array(
				'filter' => array(
					'%NAME' => $lastName,
					'%LAST_NAME' => $name
				),
				'select' => $selectContactLead
			);

			$requestNames = [$lastName . $name, $name . $lastName];

			foreach ($requestNames as $key) {
				$requestParamsContact[] = array(
					'filter' => array(
						'NAME' => $key
					),
					'select' => array(
						'ID', 'NAME', 'LAST_NAME'
					)
				);
			}

			foreach ($requestNames as $key) {
				$requestParamsCompany[] = array(
					'filter' => array(
						'TITLE' => $key
					),
					'select' => $selectCompany
				);
			}
			break;

		case 'NAME ONLY':
			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
				),
				'select' => array(
					'ID', 'TITLE'
				)
			);

			$requestParamsContact[] = array(
				'filter' => array(
					'NAME' => $name
				),
				'select' => $selectContactLead
			);

			$requestParamsCompany[] = array(
				'filter' => array(
					'TITLE' => $name
				),
				'select' => array(
					'ID', 'TITLE'
				)
			);
			break;
	}

	Debugger::writeToLog($requestParams, LOG, 'requestParams');
	Debugger::writeToLog($requestParamsCompany, LOG, 'requestParamsCompany');
	Debugger::writeToLog($requestParamsContact, LOG, 'requestParamsContact');
	// ---!

	// !--- Выполняем запросы 
	foreach ($requestParamsContact as $key) {
		$try = CRestPlus::call('crm.contact.list', $key)['result'];
		Debugger::writeToLog($try, REQUEST_LOG, 'crm.contact.list' . 'requestParamsContact');
		if (isset($try)) {
			foreach ($try as $element) {
				$result['CONTACT'][] = $element;
			}
		}
	}

	$try = CRestPlus::call('crm.contact.list', $requestParams)['result'];
	Debugger::writeToLog($try, REQUEST_LOG, 'crm.contact.list' . 'requestParams');
	if (isset($try)) {
		foreach ($try as $element) {
			$result['CONTACT'][] = $element;
		}
	}

	foreach ($requestParamsCompany as $key) {
		$try = CRestPlus::call('crm.company.list', $key)['result'];
		Debugger::writeToLog($try, REQUEST_LOG, 'crm.company.list' . 'requestParamsCompany');
		if (isset($try)) {
			foreach ($try as $element) {
				$result['COMPANY'][] = $element;
			}
		}
	}

	$try = CRestPlus::call('crm.lead.list', $requestParams)['result'];
	Debugger::writeToLog($try, REQUEST_LOG, 'crm.lead.list' . 'requestParams');
	if (isset($try)) {
		foreach ($try as $element) {
			$result['LEAD'][] = $element;
		}
	}

	if (isset($requestParamsReverse)) {
		Debugger::writeToLog($requestParamsReverse, LOG, 'requestParamsReverse');

		$try = CRestPlus::call('crm.lead.list', $requestParamsReverse)['result'];
		Debugger::writeToLog($try, REQUEST_LOG, 'crm.lead.list' . 'requestParamsReverse');
		if (isset($try)) {
			foreach ($try as $element) {
				$result['LEAD'][] = $element;
			}
		}

		$try = CRestPlus::call('crm.contact.list', $requestParamsReverse)['result'];
		Debugger::writeToLog($try, REQUEST_LOG, 'crm.contact.list' . 'requestParamsReverse');
		if (isset($try)) {
			foreach ($try as $element) {
				$result['CONTACT'][] = $element;
			}
		}
	}
	// ---!

	Debugger::writeToLog($result, LOG, 'findDuplicatesByName');
	return makeIdsArray($result, $entityId);
}

// Проверить какой вариант поиска по имени использовать
function checkNames($crmEntity)
{
	if ($crmEntity['LAST_NAME'] and $crmEntity['NAME'] and $crmEntity['SECOND_NAME']) {
		$resultObj = array(
			$crmEntity['LAST_NAME'],
			$crmEntity['NAME'],
			$crmEntity['SECOND_NAME'],
			'NAME, LAST_NAME, SECOND_NAME'
		);
	} else if ($crmEntity['LAST_NAME'] and $crmEntity['NAME']) {
		$resultObj = array(
			$crmEntity['LAST_NAME'],
			$crmEntity['NAME'],
			null,
			'NAME, LAST_NAME'
		);
	} else if ($crmEntity['NAME']) {
		$resultObj = array(
			$crmEntity['NAME'],
			null,
			null,
			'NAME ONLY'
		);
	} else {
		Debugger::writeToLog('Не заданы поля для поиска по имени', LOG, 'checkNames');
		return "error";
	}
	Debugger::writeToLog($resultObj, LOG, 'checkNames');
	return $resultObj;
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
 * @param string $typeOfDuplicates EMAIL - почты, PHONE - телефоны, TITLE - название, NAME - имя, фамилия, отчество
 */
function makeDescription($duplicatesArr, $typeOfDuplicates)
{
	Debugger::writeToLog($duplicatesArr, LOG, 'makeDescription / start /typeOfDuplicates - ' . $typeOfDuplicates);

	if (isset($duplicatesArr['LEAD'])) {
		$requestParams = array('filter' => array('ID' => $duplicatesArr['LEAD']), 'select' => array('ID', 'TITLE'));
		$lead = CRestPlus::call('crm.lead.list', $requestParams);
		Debugger::writeToLog($lead, REQUEST_LOG, 'crm.lead.list');
	}

	if (isset($duplicatesArr['COMPANY'])) {
		$requestParams = array('filter' => array('ID' => $duplicatesArr['COMPANY']), 'select' => array('ID', 'TITLE'));
		$company = CRestPlus::call('crm.company.list', $requestParams);
		Debugger::writeToLog($company, REQUEST_LOG, 'crm.company.list');
	}

	if (isset($duplicatesArr['CONTACT'])) {
		$requestParams = array('filter' => array('ID' => $duplicatesArr['CONTACT']), 'select' => array('ID', 'NAME', 'LAST_NAME'));
		$contact = CRestPlus::call('crm.contact.list', $requestParams);
		Debugger::writeToLog($contact, REQUEST_LOG, 'crm.contact.list');
	}

	$final = array();

	// TODO - Переписать в функцию
	$i = 0;
	if (isset($lead['result'])) {
		foreach ($lead['result'] as $value) {
			$final['LEAD'][$i]['ID'] = $value['ID'];
			$final['LEAD'][$i]['TITLE'] = 'Лид: ' . $value['TITLE'];
			$i++;
		}
	}

	$i = 0;
	if (isset($contact['result'])) {
		foreach ($contact['result'] as $value) {
			$final['CONTACT'][$i]['ID'] = $value['ID'];
			$final['CONTACT'][$i]['TITLE'] = 'Контакт: ' . $value['NAME'] . ' ' . $value['LAST_NAME'];
			$i++;
		}
	}

	$i = 0;
	if (isset($company['result'])) {
		foreach ($company['result'] as $value) {
			$final['COMPANY'][$i]['ID'] = $value['ID'];
			$final['COMPANY'][$i]['TITLE'] = 'Компания: ' . $value['TITLE'];
			$i++;
		}
	}
	Debugger::writeToLog($final, LOG, 'makeDescription / mid / typeOfDuplicates - ' . $typeOfDuplicates);

	$taskDesc = null;

	foreach ($final as $key => $data) {
		foreach ($data as $item) {
			$taskDesc .= "\n[url=" . DOMAIN . "/crm/" . strtolower($key) . "/details/" . $item['ID'] . "/]" . $item['TITLE'] . "[/url]";
		}
	}

	switch ($typeOfDuplicates) {
		case 'PHONE':
			$taskDesc = "Дубликаты по номеру телефона:" . $taskDesc;
			break;

		case 'EMAIL':
			$taskDesc = "Дубликаты по email:" . $taskDesc;
			break;

		case 'TITLE':
			$taskDesc = "Дубликаты по названию сущности:" . $taskDesc;
			break;

		case 'NAME':
			$taskDesc = "Дубликаты по имени, фамилии, отчеству:" . $taskDesc;
			break;
	}

	Debugger::writeToLog($taskDesc, LOG, 'makeDescription / final / typeOfDuplicates - ' . $typeOfDuplicates);
	return $taskDesc;
}

// Пост сообщения в ленту
function notifyPost($postText, $entityId, $entityTitle, $entityTypeId)
{
	$postId = CRestPlus::call('crm.livefeedmessage.add', array('fields' => array(
		'POST_TITLE' => 'Найдены дубликаты в базе клиентов' . ' / ' . $entityTitle,
		'MESSAGE' => $postText,
		'ENTITYTYPEID' => $entityTypeId,
		'ENTITYID' => $entityId
	)));
	Debugger::writeToLog($postId, REQUEST_LOG, 'crm.livefeedmessage.add');
}

// Создать задачи пользователю
function postTask($taskDescription, $entityId, $entityTitle, $entityTypeId)
{
	switch ($entityTypeId) {
		case '1':
			$entityLink = array('L_' . $entityId);
			break;
		case '3':
			$entityLink = array('C_' . $entityId);
			break;
		case '4':
			$entityLink = array('CO_' . $entityId);
			break;
	}

	$taskId = CRestPlus::call('tasks.task.add', array('fields' => array(
		'TITLE' => 'Найдены дубликаты в базе клиентов' . ' / ' . $entityTitle,
		'RESPONSIBLE_ID' => USER_ID,
		'DESCRIPTION' => $taskDescription,
		'UF_CRM_TASK' => $entityLink
	)));
	Debugger::writeToLog($taskId, REQUEST_LOG, 'tasks.task.add');
}
