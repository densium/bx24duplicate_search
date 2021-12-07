<?php
/**
 * Создает строку с ссылкой на CRM Сущность
 */
function makeUrlString($crmEntityType, $crmEntityId, $entityName, $title)
{
	return "\n[url=" . DOMAIN . "/crm/" . strtolower($crmEntityType) . "/details/" . $crmEntityId . "/]" . $entityName . $title . "[/url]";
}

/**
 * Получает массив дубликатов и возвращает описание задачи по ним.
 * @param string $duplicatesArr['TYPE_OF_DUPLICATES'] EMAIL - почты, PHONE - телефоны, TITLE - название, NAME - имя, фамилия, отчество запросы, INSTA - instagram аккаунт
 * @param array $writtenDuplicates массив c ключами ID уже добавленных в описание сущностей
 */
function makeDescription($duplicatesArr, &$writtenDuplicates)
{
	$keys = ['LEAD' => 'Лид: ', 'CONTACT' => 'Контакт: ', 'COMPANY' => 'Компания: '];
	$taskDesc = '';

	foreach ($keys as $key => $entityName) {
		if (isset($duplicatesArr[$key])) {
			foreach ($duplicatesArr[$key] as $values) {
				if (!array_key_exists($values['ID'], $writtenDuplicates[$key])) {
					$taskDesc .= $key == 'CONTACT' ?
					makeUrlString($key, $values['ID'], $entityName, $values['NAME'] . ' ' . $values['LAST_NAME']) :
					makeUrlString($key, $values['ID'], $entityName, $values['TITLE']);
					$writtenDuplicates[$key][$values['ID']] = '';
				}
			}
		}
	}

	$typeOfDuplicates = [
		'PHONE' => "Дубликаты по номеру телефона:", 
		'EMAIL' => "Дубликаты по email:",
		'TITLE' => "Дубликаты по названию сущности:",
		'NAME' => "Дубликаты по имени, фамилии, отчеству:",
		'INSTA' => "Дубликаты по instagram аккаунту:"
	];

	return $taskDesc != '' ?  "\n" . $typeOfDuplicates[$duplicatesArr['TYPE_OF_DUPLICATES']] . $taskDesc : '';
}

/**
 * Пост сообщения в ленту
 */
function notifyPost($postText, $entityId, $entityTitle, $entityTypeId)
{
	$method = 'crm.livefeedmessage.add';
	$requestParams = array('fields' => array(
		'POST_TITLE' => 'Найдены дубликаты в базе клиентов' . ' / ' . $entityTitle,
		'MESSAGE' => $postText,
		'ENTITYTYPEID' => $entityTypeId,
		'ENTITYID' => $entityId
	));

	$postId = CRestPlus::call($method, )['result'];
	Debugger::writeToLogSet($requestParams, $postId,'ID:' . ENTITY_ID . ' По запросу ' . $method . ' функции ' . __FUNCTION__ . ' создан пост в ленту');
	return $postId;
}

/**
 * Создает задачу для пользователю
 */
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
	
	$method = 'tasks.task.add';
	$requestParams = array('fields' => array(
		'TITLE' => 'Найдены дубликаты в базе клиентов' . ' / ' . $entityTitle,
		'RESPONSIBLE_ID' => USER_ID,
		'DESCRIPTION' => $taskDescription,
		'UF_CRM_TASK' => $entityLink
	));

	$taskId = CRestPlus::call($method, $requestParams)['result'];
	Debugger::writeToLogSet($requestParams, $taskId['task']['id'], 'ID:' . ENTITY_ID . ' По запросу ' . $method . ' функции ' . __FUNCTION__ . ' создана задача');
	return $taskId['task']['id'];
}
