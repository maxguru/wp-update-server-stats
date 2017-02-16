<?php
namespace Wpup;
use \PDO, \PDOStatement;
require 'Summary.php';

class BasicLogAnalyser {
	/**
	 * A replacement for version numbers that are clearly invalid or obfuscated.
	 */
	const INVALID_VER_REPLACEMENT = 'obfuscated';

	private $logFilename;

	/**
	 * @var int Current log line. Note that this is relative to the first processed entry, not the start of the file.
	 */
	private $currentLineNumber = 0;

	/**
	 * @var string[] We'll generate daily stats for these API request parameters.
	 */
	private $enabledParameters = array(
		'installed_version', 'wp_version', 'php_version', 'action',
		'wp_version_aggregate', 'php_version_aggregate',
	);

	/**
	 * @var int[string] Lookup table for metric IDs.
	 */
	private $metricIds = array();

	/**
	 * @var int[string] Lookup table for plugin/theme slugs.
	 */
	private $slugIds = array();

	/**
	 * @var string The date that's currently being processed.
	 */
	private $currentDate = null;
	/**
	 * @var Summary Stats for the current date.
	 */
	private $dailyStats = null;

	/**
	 * @var int The number of log lines parsed that have the current date.
	 */
	private $currentDayLineCount = 0;

	private $encounteredSlugs = array();

	/**
	 * @var PDO Statistics database
	 */
	private $database;

	/** @var PDOStatement */
	private $insertStatement;

	public function __construct($filename, $database) {
		$this->logFilename = $filename;
		$this->database = $database;

		$this->createTables();
		$this->populateLookUps();
		$this->insertStatement = $this->database->prepare(
			'INSERT OR REPLACE INTO stats(datestamp, slug_id, metric_id, value, requests, unique_sites)
			 VALUES(:date, :slug_id, :metric_id, :value, :requests, :unique_sites)'
		);
	}

	/**
	 * Analyse a log file and save various statistics to the database.
	 *
	 * You can use the optional $fromTimestamp and $toTimestamp arguments to restrict analysis to a specific
	 * date range. This is useful if the log file is very large and/or you want to do incremental stats updates.
	 * Note: This method assumes that the file is sorted in chronological order.
	 *
	 * @param string|int $fromTimestamp
	 * @param string|int $toTimestamp
	 */
	public function parse($fromTimestamp = null, $toTimestamp = null) {
		if (isset($fromTimestamp) && !is_int($fromTimestamp)) {
			$fromTimestamp = strtotime($fromTimestamp);
		}
		if (isset($toTimestamp) && !is_int($toTimestamp)) {
			$toTimestamp = strtotime($toTimestamp);
		}

		$this->dailyStats = new Summary();
		$this->currentDate = null;

		$input = fopen($this->logFilename, 'r');

		if ( isset($fromTimestamp) ) {
			$this->output(sprintf(
				'Searching for the first entry with a timestamp equal or greater than %s',
				gmdate('Y-m-d H:i:s O', $fromTimestamp)
			));

			//Binary search the file to find the first entry that's >= $fromTimestamp.
			$firstLineOffset = $this->findFirstEntryByTimestamp($input, $fromTimestamp);
			if ( $firstLineOffset === null ) {
				$this->output("There are no log entries matching that timestamp.");
				return;
			} else {
				$this->output(sprintf(
					'Found an entry with a timestamp >= %s at offset %d.',
					gmdate('Y-m-d H:i:s O', $fromTimestamp),
					$firstLineOffset
				));
				fseek($input, $firstLineOffset);
			}
		}

		$this->currentLineNumber = 0;

		$insertLogEntry = $this->database->prepare(
			'INSERT OR IGNORE INTO "scratch"."log" (
				slug_id, "action", installed_version, wp_version, php_version, 
				wp_version_aggregate, php_version_aggregate, site_url
			)
			 VALUES(
			 	:slug_id, :action, :installed_version, :wp_version, :php_version, 
			 	:wp_version_aggregate, :php_version_aggregate, :site_url
			 )'
		);

		$this->database->beginTransaction();
		$lastHour = -1;

		while (!feof($input)) {
			$this->currentLineNumber++;

			$line = fgets($input);
			//Skip empty lines.
			if (empty($line)) {
				continue;
			}

			$entry = $this->parseLogEntry($line);
			$timestamp = $entry['timestamp'];
			$date = gmdate('Y-m-d', $timestamp);
			$slug = $entry['slug'];

			//Skip all entries older than the specified "from" timestamp.
			if (isset($fromTimestamp) && $timestamp < $fromTimestamp) {
				continue;
			}

			//The log file is sorted in chronological order, so if we reach an entry newer than the "to" timestamp,
			//we can safely skip *all* following entries.
			if (isset($toTimestamp) && $timestamp > $toTimestamp) {
				break;
			}

			//Keep track of which slugs we've seen recently.
			if (isset($this->encounteredSlugs[$slug])) {
				$this->encounteredSlugs[$slug] = max($timestamp, $this->encounteredSlugs[$slug]);
			} else {
				$this->encounteredSlugs[$slug] = $timestamp;
			}

			if ($date !== $this->currentDate) {
				$this->database->commit();

				if (isset($this->currentDate)) {
					$this->flushDay();
				}

				$this->currentDate = $date;
				$this->currentDayLineCount = 0;
				printf('%s [', $this->currentDate);
				$lastHour = -1;

				$this->database->beginTransaction();
			}

			$insertLogEntry->execute(array(
				':slug_id' => $this->slugToId($slug),
				':action' => $entry['action'],
				':installed_version' => $entry['installed_version'],
				':wp_version' => $entry['wp_version'],
				':wp_version_aggregate' => $entry['wp_version_aggregate'],
				':php_version' => $entry['php_version'],
				':php_version_aggregate' => $entry['php_version_aggregate'],
				':site_url' => $entry['site_url'],
			));
			$this->currentDayLineCount++;

			//Track the total number of requests + uniques.
			$this->dailyStats->recordEvent($date, $slug, 'total_hits', '', $entry['site_url']);

			//Rudimentary progress bar.
			$thisHour = intval(gmdate('H', $timestamp));
			if ($thisHour !== $lastHour) {
				echo str_repeat('=', max($thisHour - $lastHour, 1));
				$lastHour = $thisHour;
			}
		}

		$this->database->commit();
		$this->flushDay();
		$this->output("Done.");
	}

	/**
	 * Parse a WP Update Server log entry.
	 *
	 * @param string $line
	 * @return array
	 * @throws LogParserException
	 */
	private function parseLogEntry($line) {
		$columns = array(
			'http_method', 'action', 'slug', 'installed_version', 'wp_version',
			'site_url', 'query_string',
		);
		$result = array_fill_keys($columns, null);

		if ( preg_match('/^\[(?P<timestamp>[^\]]+)\]\s(?P<ip>\S+)\s+(?P<remainder>.+)$/', $line, $matches) ) {
			$result['timestamp'] = strtotime($matches['timestamp']);
			$result['ip'] = $matches['ip'];

			$values = explode("\t", $matches['remainder']);
			foreach($values as $index => $value) {
				if ( isset($columns[$index]) ) {
					$result[$columns[$index]] = $value;
				}
			}

			//PHP version and locale were added much later than other parameters, so they
			//don't have their own log columns. Extract them from the query string.
			$result['php_version'] = null;
			$result['locale'] = null;
			if (!empty($result['query_string'])) {
				parse_str($result['query_string'], $parameters);
				if (isset($parameters['php'])) {
					$result['php_version'] = strval($parameters['php']);
				}
				if (isset($parameters['locale'])) {
					$result['locale'] = strval($parameters['locale']);
				}
			}

			//Some sites obfuscate their WordPress version number or replace it with something weird. We don't
			//want to pollute the stats with those bogus numbers, so we'll group them all together.
			if (isset($result['wp_version'])) {
				if ($result['wp_version'] === '') {
					$result['wp_version'] = '-';
				}
				if (($result['wp_version'] !== '-') && (!$this->looksLikeNormalWpVersion($result['wp_version']))) {
					$result['wp_version'] = self::INVALID_VER_REPLACEMENT;
				}
			}

			//Aggregate WordPress and PHP patch versions (e.g. 4.7.1 => 4.7).
			foreach(['wp_version', 'php_version'] as $field) {
				$result[$field . '_aggregate'] = $this->getAggregateVersion($result[$field]);
			}
		} else {
			throw new LogParserException(sprintf(
				'Failed to parse line #%d',
				$this->currentLineNumber
			));
		}

		return $result;
	}

	/**
	 * Get the major and minor parts of a version number.
	 * For example, "1.2.3-RC1" becomes "1.2".
	 *
	 * @param string|null $versionNumber
	 * @return string|null
	 */
	private function getAggregateVersion($versionNumber) {
		if ($versionNumber === null) {
			return null;
		} else if (preg_match('/^(\d{1,2}\.\d{1,3})(?:\.|$)/', $versionNumber, $matches)) {
			return $matches[1];
		} else if ($versionNumber === self::INVALID_VER_REPLACEMENT) {
			return $versionNumber;
		}
		return null;
	}

	private function flushDay() {
		$startTime = microtime(true);
		$this->database->beginTransaction();

		$this->flushSlugs();

		foreach($this->enabledParameters as $metricName) {
			$statement = $this->database->prepare(
				"INSERT OR REPLACE INTO stats(datestamp, slug_id, metric_id, value, unique_sites) 
				 SELECT :datestamp, slug_id, :metric_id, \"$metricName\", COUNT(*) AS unique_sites 
				 FROM scratch.log
				 GROUP BY slug_id, \"$metricName\""
			);
			$statement->execute(array(
				':datestamp' => $this->currentDate,
				':metric_id' => $this->metricToId($metricName),
			));
		}

		foreach($this->dailyStats->getIterator() as $row) {
			$this->insertStatement->execute([
				':date'      => $row['date'],
				':slug_id'   => $this->slugToId($row['slug']),
				':metric_id' => $this->metricToId($row['metric']),
				':value'     => $row['value'],
				':requests'  => $row['requests'],
				':unique_sites' => $row['unique_sites'],
			]);

		}
		$this->dailyStats->clear();

		//Clear the temporary table.
		$this->database->exec('DELETE FROM scratch.log');

		$this->database->commit();

		$this->output(sprintf(
			"] %s lines, DB flush: %.3fs",
			number_format($this->currentDayLineCount, 0, '.', ','),
			microtime(true) - $startTime
		));
		flush();
	}

	/**
	 * @param string $slug
	 * @return int
	 */
	private function slugToId($slug) {
		if (!isset($this->slugIds[$slug])) {
			$insert = $this->database->prepare('INSERT INTO slugs(slug) VALUES(:slug)');
			$insert->execute(array('slug' => $slug));
			$this->slugIds[$slug] = intval($this->database->lastInsertId());
		}
		return $this->slugIds[$slug];
	}

	/**
	 * @param string $metricName
	 * @return int
	 */
	private function metricToId($metricName) {
		if (!isset($this->metricIds[$metricName])) {
			$insert = $this->database->prepare('INSERT INTO metrics(metric) VALUES(:metric)');
			$insert->execute(array('metric' => $metricName));
			$this->metricIds[$metricName] = intval($this->database->lastInsertId());
		}
		return $this->metricIds[$metricName];
	}

	private function flushSlugs() {
		$insertSlug = $this->database->prepare(
			'INSERT INTO slugs(slug, last_seen_on) VALUES(:slug, :last_seen_on)'
		);
		$updateSlugTimestamp = $this->database->prepare(
			'UPDATE slugs SET last_seen_on = MAX(last_seen_on, :last_seen_on) WHERE slug = :slug'
		);

		foreach($this->encounteredSlugs as $slug => $lastSeenTimestamp) {
			$params = array(
				':slug' => $slug,
				':last_seen_on' => gmdate('Y-m-d', $lastSeenTimestamp),
			);
			if ( !isset($this->slugIds[$slug]) ) {
				//New slug, save it.
				$insertSlug->execute($params);
				$this->slugIds[$slug] = $this->database->lastInsertId();
			} else {
				//Previously known slug, update the last_seen_on timestamp.
				$updateSlugTimestamp->execute($params);
			}
		}
	}

	private function looksLikeNormalWpVersion($version) {
		return preg_match('/^\d{1,2}\.\d/', $version);
	}

	/**
	 * Find the first log entry that has a timestamp greater or equal to a specific timestamp.
	 *
	 * @param resource $fileHandle Log file handle.
	 * @param int $targetTimestamp Unix timestamp to look for.
	 * @return int File offset of the found entry, or NULL if there are no entries with the required timestamp.
	 */
	private function findFirstEntryByTimestamp($fileHandle, $targetTimestamp) {
		$originalPosition = ftell($fileHandle);

		//Calculate the file size.
		fseek($fileHandle, 0, SEEK_END);
		$fileSize = ftell($fileHandle);

		//An empty file definitely doesn't contain the timestamp that we're looking for.
		if ($fileSize == 0) {
			return null;
		}

		//Check the first line. Since we skip the first line after each seek, there's no way we
		//would reach it otherwise.
		fseek($fileHandle, 0);
		$line = fgets($fileHandle);
		$entry = $this->parseLogEntry($line);
		$timestamp = $entry['timestamp'];
		if ( $timestamp >= $targetTimestamp ) {
			//The first line has a timestamp greater or equal to the one we're looking for.
			return 0;
		}

		$beginning = 0;
		$end = $fileSize - 1;

		while($beginning <= $end) {
			$middle = floor($beginning + (($end - $beginning) / 2));
			fseek($fileHandle, $middle);

			//Read and discard a line since we're probably in the middle of one.
			fgets($fileHandle);

			//Find a line that we can parse.
			$comparison = null;
			while( !feof($fileHandle) && ($comparison === null) ) {
				$line = fgets($fileHandle);
				$comparison = null;

				if ( !empty($line) ) {
					try {
						$entry = $this->parseLogEntry($line);
						$timestamp = $entry['timestamp'];
						$comparison = $timestamp - $targetTimestamp;
					} catch (LogParserException $ex) {
						//Eat the exception and skip the line.
					}
				}
			}

			if ( $comparison === null || $comparison >= 0 ) {
				//We found a line that's greater or equal to the target, or all lines after the current
				//midpoint are malformed. So look before the midpoint.
				$end = $middle - 1;
			} else {
				//Otherwise, continue searching after the midpoint.
				$beginning = $middle + 1;
			}
		}

		fseek($fileHandle, $beginning);
		fgets($fileHandle); //Discard a line

		$targetPosition = ftell($fileHandle);
		if ( $targetPosition >= $fileSize ) {
			//We reached the end of the file without ever finding the specified timestamp.
			return null;
		}

		//Restore the original file position.
		fseek($fileHandle, $originalPosition);

		return $targetPosition;
	}

	/**
	 * Create database tables for summary statistics, if they don't already exist.
	 */
	private function createTables() {
		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS metrics (
				metric_id int not null primary key autoincrement,
				metric varchar(50) unique not null
			)'
		);

		$this->database->beginTransaction();

		$result = $this->database->query('SELECT COUNT(*) FROM metrics', \PDO::FETCH_COLUMN, 0);
		if (intval($result->fetchColumn()) === 0) {
			$metrics = array_merge($this->enabledParameters, ['total_hits']);
			$insert = $this->database->prepare('INSERT INTO metrics(metric) VALUES(:metric)');
			foreach($metrics as $metric) {
				$insert->execute(array('metric' => $metric));
			}
		}

		$this->database->commit();

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS slugs (
			   slug_id int not null primary key autoincrement,
			   slug varchar(250) unique not null,
			   last_seen_on datetime
			);'
		);

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS stats (
			   datestamp date not null,
			   slug_id unsigned int not null,
			   metric_id unsigned int not null,
			   value text,
			   requests unsigned int not null default 0,
			   unique_sites unsigned int not null default 0
			);'
		);

		$this->database->exec('CREATE UNIQUE INDEX IF NOT EXISTS id_context on stats (datestamp, slug_id, metric_id, value);');

		//A temporary database for one day of log data.
		$this->database->exec("ATTACH DATABASE '' AS scratch");

		$this->database->exec(
			'CREATE TABLE scratch.log (
				"slug_id" INTEGER  NOT NULL,
				"action" VARCHAR(30) NULL,
				"installed_version" VARCHAR(30) NULL,
				"wp_version" VARCHAR(20) NULL,
				"php_version" varCHAR(20) NULL,
				"wp_version_aggregate" VARCHAR(15) NULL,
				"php_version_aggregate" VARCHAR(15) NULL,
				"site_url" VARCHAR(100) NULL
			)'
		);

		//For most statistics, we only care about unique sites, not total requests.
		$this->database->exec(
			'CREATE UNIQUE INDEX scratch.id_unique_requests ON log ( 
				"slug_id" ASC,
				"site_url" ASC
			)'
		);
	}

	private function populateLookUps() {
		$metrics = $this->database->query('SELECT metric_id, metric FROM metrics', PDO::FETCH_ASSOC);
		foreach($metrics as $row) {
			$this->metricIds[$row['metric']] = intval($row['metric_id']);
		}
		$metrics->closeCursor();

		$slugs = $this->database->query('SELECT slug_id, slug FROM slugs', PDO::FETCH_ASSOC);
		foreach($slugs as $row) {
			$this->slugIds[$row['slug']] = intval($row['slug_id']);
		}
		$slugs->closeCursor();
	}

	public function getLastProcessedDate() {
		$lastDate = $this->database->query('SELECT MAX("datestamp") AS last_processed_date FROM "stats"')->fetchColumn(0);
		if ( !empty($lastDate) ) {
			return $lastDate;
		}
		return null;
	}

	private function output($message) {
		echo $message, "\n";
	}
}