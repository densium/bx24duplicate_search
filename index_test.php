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

// Получить лиды, сделки, компании по названию
// Искать часть текущей строки в названии
function findDuplicatesByTitle($crmEntityTitle)
{
	$requestParamsSubstr = array('filter' => array('%TITLE' => $crmEntityTitle), 'select' => array('ID', 'TITLE'));

	$requestParamsSubstrContact = array('filter' => array('%NAME' => $crmEntityTitle), 'select' => array('ID', 'NAME', 'LAST_NAME'));

	$result['lead'] = CRestPlus::call('crm.lead.list', $requestParamsSubstr)['result'];
	$result['company'] = CRestPlus::call('crm.company.list', $requestParamsSubstr)['result'];
	$result['contact'] = CRestPlus::call('crm.contact.list', $requestParamsSubstrContact)['result'];

	Debugger::writeToLog($result, LOG, 'findDuplicatesByTitle');
	return $result;
}

function arrSwap($arr, $elNum1, $elNum2)
{
	$temp = $arr[$elNum1];
	$arr[$elNum1] = $arr[$elNum2];
	$arr[$elNum2] = $temp;
	return $arr;
}

/*
	Получить контакты и лиды и компании с именем, фамилией и отчеством
	Какие есть нюансы:
	2. Проверять наличие аргументов
	3. Прямой поиск имя = имя, фамилия = фамилия и так далее
	4. Обратный имя = фамилия и фамилия = имя
	5. Поиск внутри имени Контакта

	TODO - $entityId
	*/
function findDuplicatesByNames($name, $lastName, $secondName, $searchVariant)
{
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

			// поиск внутри имени контакта
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
	$i = 0;
	foreach ($requestParamsContact as $key) {
		$try = CRestPlus::call('crm.contact.list', $key)['result'];
		if (!empty($try)) {
			$result['contact'][$i] = $try;		
		}
		$i++;
	}
	
	$i = 0;
	foreach ($requestParamsCompany as $key) {
		$try = CRestPlus::call('crm.company.list', $key)['result'];
		if (!empty($try)) {
			$result['company'][$i] = $try;		
		}
		$i++;
	}
	
	$result['lead'] = CRestPlus::call('crm.lead.list', $requestParams)['result'];
	$result['contact'][] = CRestPlus::call('crm.contact.list', $requestParams)['result'];

	// if (isset($requestParamsReverse)) {
	// 	$try = CRestPlus::call('crm.lead.list', $requestParamsReverse)['result'];
		
	// 	$result['lead'] = array_merge($resultArr, $result['lead']); 
		
	// 	$resultArr = CRestPlus::call('crm.contact.list', $requestParamsReverse)['result'];
	// 	$result['contact'] = array_merge($result['contact'], $resultArr);

	// 	Debugger::writeToLog($requestParamsReverse, LOG, 'requestParamsReverse');
	// }	
	// ---!

	Debugger::writeToLog($result, LOG, 'findDuplicatesByName');
	return $result;
}

// !--- Выполнение скрипта

// Проверить передан ли параметр ID сущности, без которого не получится поиск
if ($_REQUEST['ID']) {
	Debugger::writeToLog($_REQUEST, LOG, '$_REQUEST');
} else {
	Debugger::writeToLog('Не задан ID cущности для поиска', LOG, 'Error: Missing parametr ID');
	die;
}

// Записать тип сущности если он передан в параметре, если нет, то ориентироваться на события
if ($_REQUEST['ENTITY_TYPE_ID']) {
	$entityTypeId = $_REQUEST['ENTITY_TYPE_ID'];
} else {
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
}

// Получить данные сущности по ID, найти дубликаты
$crmEntity = getCrmEntity($_REQUEST['ID'], $entityTypeId);

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
	Debugger::writeToLog('Не заданы поля для поиска', LOG, 'Error: Missing parameters');
	exit;
}
