<?php
// namespace projects\libs\debugger;
class Debugger extends Exception {

	/**
	* Вывод ошибок и предупреждений
	*
	* @var bool debug - 1 - выводим; 0 - нет
	*/
	public static function displayErrors ($debug = 0) {
		if (!$debug) {
			ini_set('display_errors',0);
			ini_set('display_startup_errors',0);
			error_reporting(0);
			ini_set('log_errors',0);
		} else {
			ini_set('display_errors',1);
			ini_set('display_startup_errors',1);
			error_reporting(-1);
			ini_set('log_errors',1);
		}
	}

	/**
	* Функция логгирования
	* @var $data      - данные для записи
	* @var str $path  - путь к фалу
	* @var str $title - 
	*/
	public static function writeToLog($data, $path, $title = 'DEBUG', $bool = true) {
		if ($bool) {
			$log = "\n--------------------\n";
			$log .= date('d.m.Y H:i:s')."\n";
			$log .= "Caption: ".$title."\n";
			$log .= print_r($data, 1);
			$log .= "\n--------------------\n";
			file_put_contents($path, $log, FILE_APPEND);
		}
		return true;
	}

	/**
	* Функция вывода данных на экран
	* @var $data - выводимые данные
	*/
	public static function debug($data, $die = false, $dieMessage = 'die') {
		echo '<pre>';
		print_r ($data);
		echo '</pre>';
		if ($die) die('Работа скрипта завершена принудительно: '.$dieMessage);
	}

	/**
	* Запись данных в php файл
	* @var $data     - данные для записи в файл
	* @var str $path - путь к файлу
	*/
	public static function saveParams($data, $path) {
		$config = "<?php\n";
		$config .= "\$appsConfig = ".var_export($data, true)."\n";
		$config .= "?>";

		file_put_contents($path, $config);
		return true;
	}

	/**
	* тестируемый метод
	*/
	public static function memoryTest () {
		$str = 'Текущее использование памяти: <b>'.memory_get_usage().'</b> байт'."\n";
		$str .= 'Пиковое значение объема памяти: <b>'.memory_get_peak_usage().'</b> байт';
		self::debug ($str);
	}

}