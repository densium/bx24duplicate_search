<?php
require_once __DIR__ . '/search_functions.php';
require_once __DIR__ . '/post_functions.php';
require_once __DIR__ . '/libs/crest/CRestPlus.php';
require_once __DIR__ . '/libs/debugger/Debugger.php';

/**
 * Insta аккаунт поле в Лиде
 */
define('INST_FIELD_ID_ACC', 'UF_CRM_INSTAGRAM_WZ');
/**
 * Insta ссылка поле в Лиде
 */ 
define('INST_FIELD_ID_ADR', 'UF_CRM_INSTAGRAM');
/**
 * Insta аккаунт поле в Контакте
 */
define('INST_FIELD_ID_ACC_CON', 'UF_CRM_INSTAGRAM_WZ');
/**
 * Insta ссылка поле в Контакте
 */
define('INST_FIELD_ID_ADR_CON', 'UF_CRM_5E96D401717C0');
/**
 * Insta аккаунт поле в Компании
 */
define('INST_FIELD_ID_ACC_COMP', 'UF_CRM_5E3171B8A45C6');
/**
 * Insta ссылка поле в Компании
 */
define('INST_FIELD_ID_ADR_COMP', 'UF_CRM_5CE3EE5FD7439');

/**
 * Массив с ключами используемых сущностей, cо значениями их select параметров для запросов
 */
define('SELECT_ARR', ['LEAD' => ['ID', 'TITLE'], 'COMPANY' => ['ID', 'TITLE'], 'CONTACT' => ['ID', 'NAME', 'LAST_NAME']]);
/**
 * Ссылка на портал для создания задач
 */
define('DOMAIN', 'https://djemdecor.bitrix24.ru');
/**
 * Пользователь в системе на кого будут ставится задачи
 */
define('USER_ID', 13);
/**
 * Тестовый пользователь в системе на кого будут ставится задачи, если в комментарии к сущности указано: "TEST"
 */
define('USER_ID_TEST', 11327);
/**
 * Путь к файлу логов для запросов
 */
define('PREARRANGED_PATH', 'log_request.txt');
/**
 * Путь к файлу общих логов
 */
define('LOG', 'log_main.txt');

// 1. Можно ли запускать скрипт 2.Получить данные 3.Проверить на дубликаты 4.Выдать данные (задача и уведомление в ленту).

// Проверить передан ли параметр ID сущности, без которого не получится поиск
if (empty($_REQUEST['ID']) or $_REQUEST['ID'] === 0) {
	Debugger::writeToLog($_REQUEST, LOG, 'ID:' . $_REQUEST['ID'] . ' Завершить скрипт: Не задан параметр ID для поиска CRM cущности');
	exit;
} else {
	define('ENTITY_ID', $_REQUEST['ID']);
	Debugger::writeToLog($_REQUEST, LOG, 'ID:' . ENTITY_ID . ' Старт скрипта');
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
			$entityTypeId = 4;
			break;
	}
} else {
	$entityTypeId = $_REQUEST['ENTITY_TYPE_ID'];
}

// Получить данные сущности по ID
$try = getCrmEntity($_REQUEST['ID'], $entityTypeId);
if (isset($try)) {
	$crmEntity = $try;
} else {
	Debugger::writeToLog($try, LOG, 'ID:' . ENTITY_ID . ' Завершить скрипт: Не найдена CRM сущность');
	exit;
}

// Найти дубликаты
$duplicates = [];
foreach (['TITLE', 'PHONE', 'EMAIL', 'NAME', 'INSTA'] as $key) {
	switch ($key) {
		case 'TITLE':
			$try = findDuplicatesByTitle($crmEntity['TITLE']); // На удаление
			break;
		case 'PHONE':
			$try = $crmEntity['HAS_PHONE'] == 'Y' ? findDuplicates(parsePhones($crmEntity), 'PHONE') : null;
			break;
		case 'EMAIL':
			$try = $crmEntity['HAS_EMAIL'] == 'Y' ? findDuplicates(parseEmails($crmEntity), 'EMAIL') : null;			
			break;
		case 'NAME':
			$namesArr = checkNames($crmEntity);
			$try = $namesArr ? findDuplicatesByNames($namesArr) : null;
			break;
		case 'INSTA':
			$instaAcc = parseInstaAcc($crmEntity);
			$try = $instaAcc ? findDuplicatesByInstagram($instaAcc, addInstaWebAdr($instaAcc)) : null;
			break;

	}
	if (isset($try)) {
		$duplicates[] = $try;
	}
}

// Проверить найдены ли дубликаты
if (empty($duplicates)) {
	Debugger::writeToLog(null, LOG, 'ID:' . ENTITY_ID . ' Завершить скрипт: Дубликаты в базе не обнаружены');
	exit;
} else {
	Debugger::writeToLog($duplicates, LOG, 'ID:' . ENTITY_ID . ' Обнаружены дубликаты в базе');
	$description = '';
}

// Массив с ключами по ID сущностей, чтобы не записывать в задачу несколько раз одно и то же по разным поискам
$writtenDuplicates = ['LEAD' => [ENTITY_ID => ''], 'CONTACT' => [], 'COMPANY' => []];
// Для каждого типа дубликатов создать текстовое описание с ссылками и добавить в общее описание
foreach ($duplicates as $duplicatesArr) {
	$description .= makeDescription($duplicatesArr, $writtenDuplicates);
}
Debugger::writeToLog($description, LOG,  'ID:' . ENTITY_ID . ' Создано описание задачи');

// Для тестирования в бою
$responsibleId = $crmEntity['COMMENTS'] == "TEST" ? USER_ID_TEST : USER_ID;

// Тут магия, автоматический поиск дубликатов удаляет лид как раз к тому моменту когда это скрипт завершает свою работу
// Поэтому нужно ещё раз гетнуть нужную сущность и проверить не удалена ли она
sleep(3);
$try = getCrmEntity($_REQUEST['ID'], $entityTypeId);
if (isset($try['error_description'])) {
	Debugger::writeToLog($_REQUEST['flag'], LOG, 'Завершить скрипт: ' . $entityTypeId . ' cущность уже удалена');
	exit;
};

// Создать пост в ленту или задачу
// Создание поста забраковали, но я не стал тратить время на удаление функций
if (isset($_REQUEST['flag'])) {
	$_REQUEST['flag'] == 'manual' ? 
	notifyPost($description, $crmEntity['ID'], $crmEntity['TITLE'], $entityTypeId) : 
	Debugger::writeToLog($_REQUEST['flag'], LOG, 'Завершить скрипт: ' . 'Request Param flag != manual');
} else {
	$taskId = postTask($description, $crmEntity['ID'], $crmEntity['TITLE'], $entityTypeId, $responsibleId);
	Debugger::writeToLog($taskId, LOG,  'ID:' . ENTITY_ID . ' Задача создана');
}
