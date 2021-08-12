<?php
require_once __DIR__ . '/libs/crest/CRestPlus.php';
require_once __DIR__ . '/libs/debugger/Debugger.php';

define('LOG', 'log_new.txt');
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
	return $result['result'] ?? null;
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

/**
 * Получить дубли по названию TITLE
 * @param array $crmEntityTitle название crm сущность 
 */
function findDuplicatesByTitle($crmEntityTitle)
{
	$requestParamsSubstr = array('filter' => array('%TITLE' => $crmEntityTitle), 'select' => array('ID', 'TITLE'));

	$requestParamsSubstrContact = array('filter' => array('%NAME' => $crmEntityTitle), 'select' => array('ID', 'NAME', 'LAST_NAME'));

	$try = CRestPlus::call('crm.lead.list', $requestParamsSubstr)['result'];
	if (!empty($try)) {
		$result['lead'] = $try;
	}
	$try = CRestPlus::call('crm.company.list', $requestParamsSubstr)['result'];
	if (!empty($try)) {
		$result['company'] = $try;
	}

	$try = CRestPlus::call('crm.contact.list', $requestParamsSubstrContact)['result'];
	if (!empty($try)) {
		$result['contact'] = $try;
	}

	Debugger::writeToLog($result, LOG, 'findDuplicatesByTitle');
	return $result;
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
function findDuplicatesByNames($namesObj)
{
	if ($namesObj === 'error') {
		Debugger::writeToLog('Не заданы поля для поиска по имени', LOG, 'findDuplicatesByNames');
		return null;
	}

	$lastName = $namesObj[0];
	$name = $namesObj[1];
	$secondName = $namesObj[2];
	$searchVariant = $namesObj[3];

	// !--- Формируем запросы
	switch ($searchVariant) {
		case 'NAME, LAST_NAME, SECOND_NAME':
			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
					'%LAST_NAME' => $lastName,
					'%SECOND_NAME' => $secondName
				),
				'select' => array(
					'ID', 'NAME', 'LAST_NAME'
				)
			);

			$requestParamsReverse = array(
				'filter' => array(
					'%NAME' => $lastName,
					'%LAST_NAME' => $name,
					'%SECOND_NAME' => $secondName
				),
				'select' => array(
					'ID', 'NAME', 'LAST_NAME'
				)
			);

			// поиск внутри имени контакта и в названии компании
			$arrNames = array($lastName, $name, $secondName);

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
					'select' => array(
						'ID', 'NAME', 'LAST_NAME'
					)
				);
			}

			foreach ($requestNames as $key) {
				$requestParamsCompany[] = array(
					'filter' => array(
						'TITLE' => implode(" ", $key)
					),
					'select' => array(
						'ID', 'TITLE'
					)
				);
			}
			break;

		case 'NAME, LAST_NAME':
			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
					'%LAST_NAME' => $lastName
				),
				'select' => array(
					'ID', 'NAME', 'LAST_NAME'
				)
			);

			$requestParamsReverse = array(
				'filter' => array(
					'%NAME' => $lastName,
					'%LAST_NAME' => $name
				),
				'select' => array(
					'ID', 'NAME', 'LAST_NAME'
				)
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
					'select' => array(
						'ID', 'TITLE'
					)
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
				'select' => array(
					'ID', 'NAME', 'LAST_NAME'
				)
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
		if (!empty($try)) {
			foreach ($try as $element) {
				$result['contact'][] = $element;
			}
		}
	}

	$try = CRestPlus::call('crm.contact.list', $requestParams)['result'];
	if (!empty($try)) {
		foreach ($try as $element) {
			$result['contact'][] = $element;
		}
	}

	foreach ($requestParamsCompany as $key) {
		$try = CRestPlus::call('crm.company.list', $key)['result'];
		if (!empty($try)) {
			foreach ($try as $element) {
				$result['company'][] = $element;
			}
		}
	}

	$try = CRestPlus::call('crm.lead.list', $requestParams)['result'];
	if (!empty($try)) {
		foreach ($try as $element) {
			$result['lead'][] = $element;
		}
	}

	if (isset($requestParamsReverse)) {
		$try = CRestPlus::call('crm.lead.list', $requestParamsReverse)['result'];
		if (!empty($try)) {
			foreach ($try as $element) {
				$result['lead'][] = $element;
			}
		}

		$try = CRestPlus::call('crm.contact.list', $requestParamsReverse)['result'];
		if (!empty($try)) {
			foreach ($try as $element) {
				$result['contact'][] = $element;
			}
		}

		Debugger::writeToLog($requestParamsReverse, LOG, 'requestParamsReverse');
	}
	// ---!

	Debugger::writeToLog($result, LOG, 'findDuplicatesByName');
	return $result;
}

// Проверить какой вариант поиска по имени использовать
function checkNames($crmEntity) {
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
function postTask() {
	return null;
}
