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

	return $result;
}

// TODO - Написать эту функцию
// При этом при поиске по Имени, Фамилии и Отчеству надо учесть, что могут быть перепутаны они местами, например Имя указано в поле Фамилия и наоборот.
function findDuplicatesByName($entityId, $name, $lastName, $secondName)
{
	/*
	Получить контакты и лиды с именем, фамилией и отчеством
	Какие есть варианты:
	2. Проверять наличие аргументов
	3. Прямой поиск имя = имя, фамилия = фамилия и так далее
	4. Обратный имя = фамилия и фамилия = имя
	5. Поиск внутри имени Контакта
	*/

	if (isset($name) and isset($lastName) and isset($secondName)) {
		$searchVariant = 'NAME, LAST_NAME, SECOND_NAME';
	} else if (empty($secondName) and isset($name) and isset($lastName)) {
		$searchVariant = 'NAME, LAST_NAME';
	} else if (empty($secondName) and empty($lastName) and isset($name)) {
		$searchVariant = 'NAME ONLY';
	}

	switch ($searchVariant) {
		case 'NAME, LAST_NAME, SECOND_NAME':
			$requestNames[] = $lastName;
			$requestNames[] = $name;
			$requestNames[] = $secondName;

			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
					'%LAST_NAME' => $lastName,
					'%SECOND_NAME' => $secondName
				)
			);
			
			$requestParamsContact = array(
				'filter' => array(
					'NAME' => implode(' ', $requestNames),
				)
			);

			$requestParamsReverse = array(
				'filter' => array(
					'%NAME' => $lastName,
					'%LAST_NAME' => $name,
					'%SECOND_NAME' => $secondName
				)
			);

			$result['contact'] = CRestPlus::call('crm.contact.list', $requestParams)['result'];
			break;

		case 'NAME, LAST_NAME':
			$requestNames[] = $lastName;
			$requestNames[] = $name;

			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
					'%LAST_NAME' => $lastName
				)
			);

			$requestParamsReverse = array(
				'filter' => array(
					'%NAME' => $lastName,
					'%LAST_NAME' => $name
				)
			);
			break;

		case 'NAME ONLY':
			$requestNames[] = $name;
			$requestParams = array(
				'filter' => array(
					'%NAME' => $name,
				)
			);
			break;
	}

	// поиск внутри имени контакта
	$requestNames = $lastName . $name . $secondName;
	$requestNames = $name . $lastName . $secondName;
	$requestNames = $secondName . $name . $lastName;

	$requestParams = array(
		'filter' => array(
			'NAME' => $requestNames,
		)
	);

	$result['lead'] = CRestPlus::call('crm.lead.list', $requestParams)['result'];
	$result['contact'] = CRestPlus::call('crm.contact.list', $requestParams)['result'];
	// В компании в названии ищем 
	$result['company'] = CRestPlus::call('crm.company.list', $requestParams)['result'];

	return null;
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

$result = findDuplicatesByTitle($crmEntity['result']['TITLE']);
Debugger::writeToLog($result, LOG, '$_REQUEST');
