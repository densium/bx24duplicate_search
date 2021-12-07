<?php
// !--- Вспомогательные функции

// Меняет элементы массива местами
function arrSwap($arr, $elKey1, $elKey2)
{
	$temp = $arr[$elKey1];
	$arr[$elKey1] = $arr[$elKey2];
	$arr[$elKey2] = $temp;
	return $arr;
}

// Поменять местами элементы массива
function arraySwap2Keys($arr)
{
	$x = array_keys($arr)[0];
	$y = array_keys($arr)[1];
	[$arr[$x], $arr[$y]] = [$arr[$y], $arr[$x]];
	return $arr;
}

// Перемешивает массив с 3мя элементами
// TODO - Переписать на нормальный алгоритм
function arrayShuffle3Keys($arr)
{
	$x = array_keys($arr)[0];
	$y = array_keys($arr)[1];
	$z = array_keys($arr)[2];

	$arrResult[] = $arr;
	$arrResult[] = arrSwap($arr, $x, $y);
	$arrResult[] = arrSwap(arrSwap($arr, $x, $y), $y, $z);
	$arrResult[] = arrSwap($arr, $y, $z);
	$arrResult[] = arrSwap($arr, $z, $x);
	$arrResult[] = arrSwap(arrSwap($arr, $z, $x), $y, $z);

	// На выходе массив с массивами
	return $arrResult;
}

// Проверяет массив наличие сущности из которой ищем дубли
function checkIdsArray($resultArr)
{
	foreach ($resultArr as $value) {
		if ($value['ID'] != ENTITY_ID) {
			$list[] = $value;
		}
	}
	return isset($list) ? $list : false;
}

// Вытаскивает номера в массив и добавляет маски 9,+7,8
function parsePhones($entityObject)
{
	$phonesArr = array();

	foreach ($entityObject['PHONE'] as $phone) {
		array_push($phonesArr, substr(preg_replace('~[^0-9]+~', '', $phone['VALUE']), -10));
	}

	// Добавление маски для проверки всех вариантов (в Б24 7 и 8 - разные номера)
	foreach ($phonesArr as $phone) {
		$phonesEdited[] = $phone;
		$phonesEdited[] = '7' . $phone;
		$phonesEdited[] = '+7' . $phone;
		$phonesEdited[] = '8' . $phone;
	}

	$phonesEdited = array_chunk($phonesEdited, 20);
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
	return $emailsArr;
}

// Проверят заполненность полей, для instagram аккаунта, парсит аккаунты
function parseInstaAcc($entityObject)
{
	$antiAPattern = '/[^@].*$/i';
	$pattern = '/instagram\.com\/(@?([a-zA-Z_1-9\.])*)\/?/i';
    $instaAdress = 'instagram.com/';

	// $pattern = '/instagram\.com\/(@?([a-zA-Z_\.])*)\/?/i'; не учитывает цифры
	// $example = 'https://www.instagram.com/rug_nik_artdevivre/?hl=ru';
	// var_dump($matches[1]); // группа в которой лежит акк

	if (isset($entityObject['WEB'])) {
		$instaArr['WEB'] = trim($entityObject['WEB'][0]['VALUE']);
	}
	if ($entityObject[INST_FIELD_ID_ACC]) {
		$instaArr[INST_FIELD_ID_ACC] = trim($entityObject[INST_FIELD_ID_ACC]);
	}
	if ($entityObject[INST_FIELD_ID_ADR]) {
		$instaArr[INST_FIELD_ID_ADR] = trim($entityObject[INST_FIELD_ID_ADR]);
	}

	if (!isset($instaArr)) {
		Debugger::writeToLogSet(null, null, 'ID:' . ENTITY_ID . ' От функции ' . __FUNCTION__ . ' получен ответ: ' . 'не заданы значения полей для поиска по instagram аккаунту');
		return null;
	}

	foreach ($instaArr as $key => $value) {
		$match = [];
		if (strpos($value, $instaAdress) != false) {
			if (preg_match($pattern, $value, $match)) {
				$result[] = $match[1];
			}
		} elseif (preg_match($antiAPattern, $value, $match)) {
				$result[] = $match[0];
			}
	}

	if (isset($result)) {
		Debugger::writeToLogSet($instaArr, $result, 'ID:' . ENTITY_ID . ' От функции ' . __FUNCTION__ . ' получен ответ');
		return $result;
	} else {
		Debugger::writeToLogSet($instaArr, null, 'ID:' . ENTITY_ID . ' От функции ' . __FUNCTION__ . ' получен ответ: ' . 'нужные значения полей для поиска по instagram аккаунту не найдены');
		return null;
	}
}

function addInstaWebAdr($instaAccArr)
{
	$instaWebArr = [];
	foreach ($instaAccArr as $key) {
		$instaWebArr[] = "https://instagram.com/" . $key; 
		$instaWebArr[] = "https://instagram.com/" . $key . "/"; 
	}	
}

// Проверить какой вариант поиска по имени использовать
// TODO - Переписать функцию
function checkNames($crmEntity)
{
	$keys = ['NAME', 'LAST_NAME', 'SECOND_NAME'];
	foreach ($keys as $key) {
		if ($crmEntity[$key]) {
			$resultObj[$key] = trim($crmEntity[$key]);
		}
	}
	
	if (isset($resultObj)) {
		return $resultObj;
	} else {
		Debugger::writeToLogSet(null, null, 'ID:' . ENTITY_ID . ' От функции ' . __FUNCTION__ . ' получен ответ: ' . 'не заданы значения полей для поиска по имени, фамилии и отчеству');
		return null;
	}
}

// ---! Вспомогательные функции

/**
 * Получить объект сущности Лид, Контакт, Компания
 * @param integer $entityId ID cущности по которой будут искаться дубликаты
 * @param integer $entityTypeId тип сущности: 3 - Контакт, 4 - Компания, 1 - Лид
 * @var integer $userID ID Пользователя, который будет получитать уведомления - пока выключено
 */
function getCrmEntity($entityId, $entityTypeId)
{
	switch ($entityTypeId) {
		case "1":
			$method = 'crm.lead.get';
			break;
		case "3":
			$method = 'crm.contact.get';
			break;
		case "4":
			$method = 'crm.company.get';
			break;
	}

	//$userID = $result['result']['ASSIGNED_BY_ID'];
	//Debugger::writeToLogSet($userID, 'userID');

	$requestParams = array('ID' => $entityId);
	$try = CRestPlus::call($method, $requestParams);
	
	if (isset($try['result'])) {
		Debugger::writeToLogSet($requestParams, $try['result']['ID'], 'ID:' . ENTITY_ID . ' По запросу ' . $method . ' функции ' . __FUNCTION__);
		return $try['result'];
	} else {
		return null;
	}
}

/**
 * Функция делает 3 запроса lead.list , сompany.list и contact.list
 * TODO - Описать параметры
 */
function makeListQueries($functionName, $typeOfDuplicates, $requestParamsArr)
{
	foreach ($requestParamsArr as $key => $requestParams) {
		$method = 'crm.' . strtolower($key) . '.list'; // crm.lead.list , crm.contact.list , crm.company.list
		$try = CRestPlus::call($method, $requestParams);
		Debugger::writeToLogSet($requestParams, $try, 'ID:' . ENTITY_ID . ' По запросу ' . $method . ' функции ' . $functionName);
		if (isset($try['result'])) {
			checkIdsArray($try['result']) ? $result[$key] = checkIdsArray($try['result']) : null;
		}
	}

	if (!isset($result)) {
		Debugger::writeToLogSet(null, null, 'ID:' . ENTITY_ID . ' По запросам функции ' . $functionName . ' сущности не найдены');
		return null;
	} else {
		$result['TYPE_OF_DUPLICATES'] = $typeOfDuplicates;
		return $result;
	}
}

/**
 * Получение дублей с таким же номером телефона или почты, выбирает не больше 20
 * @param string $valuesArr Массив почт или телефонов
 * @param string $typeOfDuplicates EMAIL - почты, PHONE - телефоны
 */
function findDuplicates($valuesArr, $typeOfDuplicates)
{
	$method = 'crm.duplicate.findbycomm';
	$requestParams = array('type' => $typeOfDuplicates, 'values' => $valuesArr[0]);
	$try = CRestPlus::call($method, $requestParams);

	// findbycomm возвращает просто ID сущностей, но для описания в задаче нужны как минимум названия
	if ($try['result']) {
		Debugger::writeToLogSet($requestParams, $try['result'], 'ID:' . ENTITY_ID . ' По запросу ' . $method . ' с типом: ' . $typeOfDuplicates);
		foreach (SELECT_ARR as $key => $value) {
			isset($try['result'][$key]) ? $requestParamsArr[$key] = array('filter' => array('ID' => $try['result'][$key]), 'select' => $value) : null;
		}
		return makeListQueries(__FUNCTION__, $typeOfDuplicates, $requestParamsArr);
	} else {
		Debugger::writeToLogSet(
			$requestParams,
			$try['result'],
			'ID:' . ENTITY_ID . ' По запросу ' . $method . ' с типом: ' . $typeOfDuplicates . ' сущности не найдены'
		);
		return null;
	}
}

/**
 * Получить дубли по названию TITLE
 * @param array $crmEntityTitle название crm сущности 
 */
function findDuplicatesByTitle($crmEntityTitle)
{
	$requestParamsArr['COMPANY'] = array('filter' => array('%TITLE' => $crmEntityTitle), 'select' => SELECT_ARR['COMPANY']);
	$requestParamsArr['CONTACT'] = array('filter' => array('%NAME' => $crmEntityTitle), 'select' => SELECT_ARR['CONTACT']);

	return makeListQueries(__FUNCTION__, 'TITLE', $requestParamsArr);
}

// Не ищет если заполнено только одно поле
// Для Компании только внутри TITLE запрос
function findDuplicatesByNames($namesObj)
{
	// Проверить входные значения
	if ($namesObj === 'error' or $namesObj == null or empty($namesObj)) {
		Debugger::writeToLogSet(null, null, 'ID:' . ENTITY_ID . ' По запросам функции ' . __FUNCTION__ . ' - Не заданы поля для поиска по имени');
		return null;
	} elseif (count($namesObj) <= 1) {
		Debugger::writeToLogSet(null, null, 'ID:' . ENTITY_ID . ' По запросам функции ' . __FUNCTION__ . ' - Указано 1 или меньше значений');
		return null;
	}

	// Сформировать основные параметры запросов
	foreach ($namesObj as $key => $value) {
		$requestParams['filter']['%' . $key] = array_values($namesObj);
	}

	// Дополнительные параметры 
	if (count($namesObj) == 2) {
		foreach ($namesObj as $key => $value) {
			$requestParams['filter']['%' . $key][] = implode(" ", $namesObj);
			$requestParams['filter']['%' . $key][] = implode(" ", arraySwap2Keys($namesObj));
		}
		$requestParamsArr['COMPANY'] = ['filter' => ['TITLE' => [implode(" ", $namesObj), implode(" ", arraySwap2Keys($namesObj))]]];
	} elseif (count($namesObj) == 3) {
		$requestNames = arrayShuffle3Keys($namesObj);

		foreach ($requestNames as $value) {
			$requestParams['filter']['%NAME'][] = implode(" ", $value);
		}

		foreach ($requestNames as $value) {
			$requestParamsArr['COMPANY']['filter']['TITLE'][] = implode(" ", $value);
		}
	}

	// Собрать массив с запросами
	foreach (SELECT_ARR as $key => $value) {
		if ($key !== 'COMPANY') {
			$requestParamsArr[$key] = $requestParams;
		}
		$requestParamsArr[$key]['select'] = $value;
	}

	// Запрос
	return makeListQueries(__FUNCTION__, 'NAME', $requestParamsArr);
}

// Ищет cущности по аккаунту instagram
function findDuplicatesByInstagram($instaAccArr, $instaWebArr = null)
{
	if ($instaAccArr === 'error' or $instaAccArr == null or empty($instaAccArr)) {
		Debugger::writeToLogSet(null, null, 'ID:' . ENTITY_ID . ' По запросам функции ' . __FUNCTION__ . ' вышла ошибка' . ' Не заданы поля для поиска по instagram');
		return null;
	}

	// 'WEB' => $instaAccArr

	$requestParamsArr['LEAD'] = array(
		'filter' => array(
			'LOGIC' => 'OR',
			'%' . INST_FIELD_ID_ACC => $instaAccArr,
			'%' . INST_FIELD_ID_ADR => $instaAccArr,
			'WEB' => $instaWebArr
		),
		'select' => SELECT_ARR['LEAD']
	);

	$requestParamsArr['CONTACT'] = array(
		'filter' => array(
			'LOGIC' => 'OR',
			'%' . INST_FIELD_ID_ACC_CON => $instaAccArr,
			'%' . INST_FIELD_ID_ADR_CON => $instaAccArr,
			'WEB' => $instaWebArr
		),
		'select' => SELECT_ARR['CONTACT']
	);

	$requestParamsArr['COMPANY'] = array(
			'filter' => array(
				'LOGIC' => 'OR',
				'%' . INST_FIELD_ID_ACC_COMP => $instaAccArr,
				'%' . INST_FIELD_ID_ADR_COMP => $instaAccArr,
				'WEB' => $instaWebArr
			),
		'select' => SELECT_ARR['COMPANY']
	);

	return makeListQueries(__FUNCTION__, 'INSTA', $requestParamsArr);
}
