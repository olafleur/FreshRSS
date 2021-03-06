<?php

class FreshRSS_EntryDAO extends Minz_ModelPdo {

	public function isCompressed() {
		return parent::$sharedDbType !== 'sqlite';
	}

	public function addEntryPrepare() {
		$sql = 'INSERT INTO `' . $this->prefix . 'entry`(id, guid, title, author, '
		     . ($this->isCompressed() ? 'content_bin' : 'content')
		     . ', link, date, is_read, is_favorite, id_feed, tags) '
		     . 'VALUES(?, ?, ?, ?, '
		     . ($this->isCompressed() ? 'COMPRESS(?)' : '?')
		     . ', ?, ?, ?, ?, ?, ?)';
		return $this->bd->prepare($sql);
	}

	public function addEntry($valuesTmp, $preparedStatement = null) {
		$stm = $preparedStatement === null ?
				FreshRSS_EntryDAO::addEntryPrepare() :
				$preparedStatement;

		$values = array(
			$valuesTmp['id'],
			substr($valuesTmp['guid'], 0, 760),
			substr($valuesTmp['title'], 0, 255),
			substr($valuesTmp['author'], 0, 255),
			$valuesTmp['content'],
			substr($valuesTmp['link'], 0, 1023),
			$valuesTmp['date'],
			$valuesTmp['is_read'] ? 1 : 0,
			$valuesTmp['is_favorite'] ? 1 : 0,
			$valuesTmp['id_feed'],
			substr($valuesTmp['tags'], 0, 1023),
		);

		if ($stm && $stm->execute($values)) {
			return $this->bd->lastInsertId();
		} else {
			$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
			if ((int)($info[0] / 1000) !== 23) {	//Filter out "SQLSTATE Class code 23: Constraint Violation" because of expected duplicate entries
				Minz_Log::error('SQL error addEntry: ' . $info[0] . ': ' . $info[1] . ' ' . $info[2]
				. ' while adding entry in feed ' . $valuesTmp['id_feed'] . ' with title: ' . $valuesTmp['title']);
			} /*else {
				Minz_Log::debug('SQL error ' . $info[0] . ': ' . $info[1] . ' ' . $info[2]
				. ' while adding entry in feed ' . $valuesTmp['id_feed'] . ' with title: ' . $valuesTmp['title']);
			}*/
			return false;
		}
	}

	public function addEntryObject($entry, $conf, $feedHistory) {
		$existingGuids = array_fill_keys(
			$this->listLastGuidsByFeed($entry->feed(), 20), 1
		);

		$nb_month_old = max($conf->old_entries, 1);
		$date_min = time() - (3600 * 24 * 30 * $nb_month_old);

		$eDate = $entry->date(true);

		if ($feedHistory == -2) {
			$feedHistory = $conf->keep_history_default;
		}

		if (!isset($existingGuids[$entry->guid()]) &&
				($feedHistory != 0 || $eDate >= $date_min || $entry->isFavorite())) {
			$values = $entry->toArray();

			$useDeclaredDate = empty($existingGuids);
			$values['id'] = ($useDeclaredDate || $eDate < $date_min) ?
				min(time(), $eDate) . uSecString() :
				uTimeString();

			return $this->addEntry($values);
		}

		// We don't return Entry object to avoid a research in DB
		return -1;
	}

	/**
	 * Toggle favorite marker on one or more article
	 *
	 * @todo simplify the query by removing the str_repeat. I am pretty sure
	 * there is an other way to do that.
	 *
	 * @param integer|array $ids
	 * @param boolean $is_favorite
	 * @return false|integer
	 */
	public function markFavorite($ids, $is_favorite = true) {
		if (!is_array($ids)) {
			$ids = array($ids);
		}
		$sql = 'UPDATE `' . $this->prefix . 'entry` '
		     . 'SET is_favorite=? '
		     . 'WHERE id IN (' . str_repeat('?,', count($ids) - 1). '?)';
		$values = array($is_favorite ? 1 : 0);
		$values = array_merge($values, $ids);
		$stm = $this->bd->prepare($sql);
		if ($stm && $stm->execute($values)) {
			return $stm->rowCount();
		} else {
			$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
			Minz_Log::error('SQL error markFavorite: ' . $info[2]);
			return false;
		}
	}

	/**
	 * Update the unread article cache held on every feed details.
	 * Depending on the parameters, it updates the cache on one feed, on all
	 * feeds from one category or on all feeds.
	 *
	 * @todo It can use the query builder refactoring to build that query
	 *
	 * @param false|integer $catId category ID
	 * @param false|integer $feedId feed ID
	 * @return boolean
	 */
	protected function updateCacheUnreads($catId = false, $feedId = false) {
		$sql = 'UPDATE `' . $this->prefix . 'feed` f '
		 . 'LEFT OUTER JOIN ('
		 .	'SELECT e.id_feed, '
		 .	'COUNT(*) AS nbUnreads '
		 .	'FROM `' . $this->prefix . 'entry` e '
		 .	'WHERE e.is_read=0 '
		 .	'GROUP BY e.id_feed'
		 . ') x ON x.id_feed=f.id '
		 . 'SET f.cache_nbUnreads=COALESCE(x.nbUnreads, 0) '
		 . 'WHERE 1';
		$values = array();
		if ($feedId !== false) {
			$sql .= ' AND f.id=?';
			$values[] = $id;
		}
		if ($catId !== false) {
			$sql .= ' AND f.category=?';
			$values[] = $catId;
		}
		$stm = $this->bd->prepare($sql);
		if ($stm && $stm->execute($values)) {
			return true;
		} else {
			$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
			Minz_Log::error('SQL error updateCacheUnreads: ' . $info[2]);
			return false;
		}
	}

	/**
	 * Toggle the read marker on one or more article.
	 * Then the cache is updated.
	 *
	 * @todo change the way the query is build because it seems there is
	 * unnecessary code in here. For instance, the part with the str_repeat.
	 * @todo remove code duplication. It seems the code is basically the
	 * same if it is an array or not.
	 *
	 * @param integer|array $ids
	 * @param boolean $is_read
	 * @return integer affected rows
	 */
	public function markRead($ids, $is_read = true) {
		if (is_array($ids)) {	//Many IDs at once (used by API)
			if (count($ids) < 6) {	//Speed heuristics
				$affected = 0;
				foreach ($ids as $id) {
					$affected += $this->markRead($id, $is_read);
				}
				return $affected;
			}

			$sql = 'UPDATE `' . $this->prefix . 'entry` '
				 . 'SET is_read=? '
				 . 'WHERE id IN (' . str_repeat('?,', count($ids) - 1). '?)';
			$values = array($is_read ? 1 : 0);
			$values = array_merge($values, $ids);
			$stm = $this->bd->prepare($sql);
			if (!($stm && $stm->execute($values))) {
				$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
				Minz_Log::error('SQL error markRead: ' . $info[2]);
				return false;
			}
			$affected = $stm->rowCount();
			if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
				return false;
			}
			return $affected;
		} else {
			$sql = 'UPDATE `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id '
				 . 'SET e.is_read=?,'
				 . 'f.cache_nbUnreads=f.cache_nbUnreads' . ($is_read ? '-' : '+') . '1 '
				 . 'WHERE e.id=? AND e.is_read=?';
			$values = array($is_read ? 1 : 0, $ids, $is_read ? 0 : 1);
			$stm = $this->bd->prepare($sql);
			if ($stm && $stm->execute($values)) {
				return $stm->rowCount();
			} else {
				$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
				Minz_Log::error('SQL error markRead: ' . $info[2]);
				return false;
			}
		}
	}

	/**
	 * Mark all entries as read depending on parameters.
	 * If $onlyFavorites is true, it is used when the user mark as read in
	 * the favorite pseudo-category.
	 * If $priorityMin is greater than 0, it is used when the user mark as
	 * read in the main feed pseudo-category.
	 * Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @todo refactor this method along with markReadCat and markReadFeed
	 * since they are all doing the same thing. I think we need to build a
	 * tool to generate the query instead of having queries all over the
	 * place. It will be reused also for the filtering making every thing
	 * separated.
	 *
	 * @param integer $idMax fail safe article ID
	 * @param boolean $onlyFavorites
	 * @param integer $priorityMin
	 * @return integer affected rows
	 */
	public function markReadEntries($idMax = 0, $onlyFavorites = false, $priorityMin = 0) {
		if ($idMax == 0) {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadEntries(0) is deprecated!');
		}

		$sql = 'UPDATE `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id '
			 . 'SET e.is_read=1 '
			 . 'WHERE e.is_read=0 AND e.id <= ?';
		if ($onlyFavorites) {
			$sql .= ' AND e.is_favorite=1';
		} elseif ($priorityMin >= 0) {
			$sql .= ' AND f.priority > ' . intval($priorityMin);
		}
		$values = array($idMax);
		$stm = $this->bd->prepare($sql);
		if (!($stm && $stm->execute($values))) {
			$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
			Minz_Log::error('SQL error markReadEntries: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads(false, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Mark all the articles in a category as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param integer $id category ID
	 * @param integer $idMax fail safe article ID
	 * @return integer affected rows
	 */
	public function markReadCat($id, $idMax = 0) {
		if ($idMax == 0) {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadCat(0) is deprecated!');
		}

		$sql = 'UPDATE `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id '
			 . 'SET e.is_read=1 '
			 . 'WHERE f.category=? AND e.is_read=0 AND e.id <= ?';
		$values = array($id, $idMax);
		$stm = $this->bd->prepare($sql);
		if (!($stm && $stm->execute($values))) {
			$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
			Minz_Log::error('SQL error markReadCat: ' . $info[2]);
			return false;
		}
		$affected = $stm->rowCount();
		if (($affected > 0) && (!$this->updateCacheUnreads($id, false))) {
			return false;
		}
		return $affected;
	}

	/**
	 * Mark all the articles in a feed as read.
	 * There is a fail safe to prevent to mark as read articles that are
	 * loaded during the mark as read action. Then the cache is updated.
	 *
	 * If $idMax equals 0, a deprecated debug message is logged
	 *
	 * @param integer $id feed ID
	 * @param integer $idMax fail safe article ID
	 * @return integer affected rows
	 */
	public function markReadFeed($id, $idMax = 0) {
		if ($idMax == 0) {
			$idMax = time() . '000000';
			Minz_Log::debug('Calling markReadFeed(0) is deprecated!');
		}
		$this->bd->beginTransaction();

		$sql = 'UPDATE `' . $this->prefix . 'entry` '
			 . 'SET is_read=1 '
			 . 'WHERE id_feed=? AND is_read=0 AND id <= ?';
		$values = array($id, $idMax);
		$stm = $this->bd->prepare($sql);
		if (!($stm && $stm->execute($values))) {
			$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
			Minz_Log::error('SQL error markReadFeed: ' . $info[2]);
			$this->bd->rollBack();
			return false;
		}
		$affected = $stm->rowCount();

		if ($affected > 0) {
			$sql = 'UPDATE `' . $this->prefix . 'feed` '
				 . 'SET cache_nbUnreads=cache_nbUnreads-' . $affected
				 . ' WHERE id=?';
			$values = array($id);
			$stm = $this->bd->prepare($sql);
			if (!($stm && $stm->execute($values))) {
				$info = $stm == null ? array(2 => 'syntax error') : $stm->errorInfo();
				Minz_Log::error('SQL error markReadFeed: ' . $info[2]);
				$this->bd->rollBack();
				return false;
			}
		}

		$this->bd->commit();
		return $affected;
	}

	public function searchByGuid($feed_id, $id) {
		// un guid est unique pour un flux donné
		$sql = 'SELECT id, guid, title, author, '
		     . ($this->isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
		     . ', link, date, is_read, is_favorite, id_feed, tags '
		     . 'FROM `' . $this->prefix . 'entry` WHERE id_feed=? AND guid=?';
		$stm = $this->bd->prepare($sql);

		$values = array(
			$feed_id,
			$id
		);

		$stm->execute($values);
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		$entries = self::daoToEntry($res);
		return isset($entries[0]) ? $entries[0] : null;
	}

	public function searchById($id) {
		$sql = 'SELECT id, guid, title, author, '
		     . ($this->isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
		     . ', link, date, is_read, is_favorite, id_feed, tags '
		     . 'FROM `' . $this->prefix . 'entry` WHERE id=?';
		$stm = $this->bd->prepare($sql);

		$values = array($id);

		$stm->execute($values);
		$res = $stm->fetchAll(PDO::FETCH_ASSOC);
		$entries = self::daoToEntry($res);
		return isset($entries[0]) ? $entries[0] : null;
	}

	protected function sqlConcat($s1, $s2) {
		return 'CONCAT(' . $s1 . ',' . $s2 . ')';	//MySQL
	}

	private function sqlListWhere($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL, $order = 'DESC', $limit = 1, $firstId = '', $filter = '', $date_min = 0) {
		if (!$state) {
			$state = FreshRSS_Entry::STATE_ALL;
		}
		$where = '';
		$joinFeed = false;
		$values = array();
		switch ($type) {
		case 'a':
			$where .= 'f.priority > 0 ';
			$joinFeed = true;
			break;
		case 's':	//Deprecated: use $state instead
			$where .= 'e1.is_favorite=1 ';
			break;
		case 'c':
			$where .= 'f.category=? ';
			$values[] = intval($id);
			$joinFeed = true;
			break;
		case 'f':
			$where .= 'e1.id_feed=? ';
			$values[] = intval($id);
			break;
		case 'A':
			$where .= '1 ';
			break;
		default:
			throw new FreshRSS_EntriesGetter_Exception('Bad type in Entry->listByType: [' . $type . ']!');
		}

		if ($state & FreshRSS_Entry::STATE_NOT_READ) {
			if (!($state & FreshRSS_Entry::STATE_READ)) {
				$where .= 'AND e1.is_read=0 ';
			}
		}
		elseif ($state & FreshRSS_Entry::STATE_READ) {
			$where .= 'AND e1.is_read=1 ';
		}
		if ($state & FreshRSS_Entry::STATE_FAVORITE) {
			if (!($state & FreshRSS_Entry::STATE_NOT_FAVORITE)) {
				$where .= 'AND e1.is_favorite=1 ';
			}
		}
		elseif ($state & FreshRSS_Entry::STATE_NOT_FAVORITE) {
			$where .= 'AND e1.is_favorite=0 ';
		}

		switch ($order) {
			case 'DESC':
			case 'ASC':
				break;
			default:
				throw new FreshRSS_EntriesGetter_Exception('Bad order in Entry->listByType: [' . $order . ']!');
		}
		/*if ($firstId === '' && parent::$sharedDbType === 'mysql') {
			$firstId = $order === 'DESC' ? '9000000000'. '000000' : '0';	//MySQL optimization. TODO: check if this is needed again, after the filtering for old articles has been removed in 0.9-dev
		}*/
		if ($firstId !== '') {
			$where .= 'AND e1.id ' . ($order === 'DESC' ? '<=' : '>=') . $firstId . ' ';
		}
		if ($date_min > 0) {
			$where .= 'AND e1.id >= ' . $date_min . '000000 ';
		}
		$search = '';
		if ($filter !== '') {
			require_once(LIB_PATH . '/lib_date.php');
			$filter = trim($filter);
			$filter = addcslashes($filter, '\\%_');
			$terms = array_unique(explode(' ', $filter));
			//sort($terms);	//Put #tags first	//TODO: Put the cheapest filters first
			foreach ($terms as $word) {
				$word = trim($word);
				if (stripos($word, 'intitle:') === 0) {
					$word = substr($word, strlen('intitle:'));
					$search .= 'AND e1.title LIKE ? ';
					$values[] = '%' . $word .'%';
				} elseif (stripos($word, 'inurl:') === 0) {
					$word = substr($word, strlen('inurl:'));
					$search .= 'AND CONCAT(e1.link, e1.guid) LIKE ? ';
					$values[] = '%' . $word .'%';
				} elseif (stripos($word, 'author:') === 0) {
					$word = substr($word, strlen('author:'));
					$search .= 'AND e1.author LIKE ? ';
					$values[] = '%' . $word .'%';
				} elseif (stripos($word, 'date:') === 0) {
					$word = substr($word, strlen('date:'));
					list($minDate, $maxDate) = parseDateInterval($word);
					if ($minDate) {
						$search .= 'AND e1.id >= ' . $minDate . '000000 ';
					}
					if ($maxDate) {
						$search .= 'AND e1.id <= ' . $maxDate . '000000 ';
					}
				} elseif (stripos($word, 'pubdate:') === 0) {
					$word = substr($word, strlen('pubdate:'));
					list($minDate, $maxDate) = parseDateInterval($word);
					if ($minDate) {
						$search .= 'AND e1.date >= ' . $minDate . ' ';
					}
					if ($maxDate) {
						$search .= 'AND e1.date <= ' . $maxDate . ' ';
					}
				} else {
					if ($word[0] === '#' && isset($word[1])) {
						$search .= 'AND e1.tags LIKE ? ';
						$values[] = '%' . $word .'%';
					} else {
						$search .= 'AND ' . $this->sqlconcat('e1.title', $this->isCompressed() ? 'UNCOMPRESS(content_bin)' : 'content') . ' LIKE ? ';
						$values[] = '%' . $word .'%';
					}
				}
			}
		}

		return array($values,
			'SELECT e1.id FROM `' . $this->prefix . 'entry` e1 '
			. ($joinFeed ? 'INNER JOIN `' . $this->prefix . 'feed` f ON e1.id_feed=f.id ' : '')
			. 'WHERE ' . $where
			. $search
			. 'ORDER BY e1.id ' . $order
			. ($limit > 0 ? ' LIMIT ' . $limit : ''));	//TODO: See http://explainextended.com/2009/10/23/mysql-order-by-limit-performance-late-row-lookups/
	}

	public function listWhere($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL, $order = 'DESC', $limit = 1, $firstId = '', $filter = '', $date_min = 0) {
		list($values, $sql) = $this->sqlListWhere($type, $id, $state, $order, $limit, $firstId, $filter, $date_min);

		$sql = 'SELECT e.id, e.guid, e.title, e.author, '
		     . ($this->isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
		     . ', e.link, e.date, e.is_read, e.is_favorite, e.id_feed, e.tags '
		     . 'FROM `' . $this->prefix . 'entry` e '
		     . 'INNER JOIN ('
		     . $sql
		     . ') e2 ON e2.id=e.id '
		     . 'ORDER BY e.id ' . $order;

		$stm = $this->bd->prepare($sql);
		$stm->execute($values);

		return self::daoToEntry($stm->fetchAll(PDO::FETCH_ASSOC));
	}

	public function listIdsWhere($type = 'a', $id = '', $state = FreshRSS_Entry::STATE_ALL, $order = 'DESC', $limit = 1, $firstId = '', $filter = '', $date_min = 0) {	//For API
		list($values, $sql) = $this->sqlListWhere($type, $id, $state, $order, $limit, $firstId, $filter, $date_min);

		$stm = $this->bd->prepare($sql);
		$stm->execute($values);

		return $stm->fetchAll(PDO::FETCH_COLUMN, 0);
	}

	public function listLastGuidsByFeed($id, $n) {
		$sql = 'SELECT guid FROM `' . $this->prefix . 'entry` WHERE id_feed=? ORDER BY id DESC LIMIT ' . intval($n);
		$stm = $this->bd->prepare($sql);
		$values = array($id);
		$stm->execute($values);
		return $stm->fetchAll(PDO::FETCH_COLUMN, 0);
	}

	public function countUnreadRead() {
		$sql = 'SELECT COUNT(e.id) AS count FROM `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id WHERE priority > 0'
		     . ' UNION SELECT COUNT(e.id) AS count FROM `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id WHERE priority > 0 AND is_read=0';
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		$all = empty($res[0]) ? 0 : $res[0];
		$unread = empty($res[1]) ? 0 : $res[1];
		return array('all' => $all, 'unread' => $unread, 'read' => $all - $unread);
	}
	public function count($minPriority = null) {
		$sql = 'SELECT COUNT(e.id) AS count FROM `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id';
		if ($minPriority !== null) {
			$sql = ' WHERE priority > ' . intval($minPriority);
		}
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return $res[0];
	}
	public function countNotRead($minPriority = null) {
		$sql = 'SELECT COUNT(e.id) AS count FROM `' . $this->prefix . 'entry` e INNER JOIN `' . $this->prefix . 'feed` f ON e.id_feed=f.id WHERE is_read=0';
		if ($minPriority !== null) {
			$sql = ' AND priority > ' . intval($minPriority);
		}
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return $res[0];
	}

	public function countUnreadReadFavorites() {
		$sql = 'SELECT c FROM ('
		     .	'SELECT COUNT(id) AS c, 1 as o FROM `' . $this->prefix . 'entry` WHERE is_favorite=1 '
		     .	'UNION SELECT COUNT(id) AS c, 2 AS o FROM `' . $this->prefix . 'entry` WHERE is_favorite=1 AND is_read=0'
		     .	') u ORDER BY o';
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		$all = empty($res[0]) ? 0 : $res[0];
		$unread = empty($res[1]) ? 0 : $res[1];
		return array('all' => $all, 'unread' => $unread, 'read' => $all - $unread);
	}

	public function optimizeTable() {
		$sql = 'OPTIMIZE TABLE `' . $this->prefix . 'entry`';	//MySQL
		$stm = $this->bd->prepare($sql);
		$stm->execute();
	}

	public function size($all = false) {
		$db = FreshRSS_Context::$system_conf->db;
		$sql = 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema=?';	//MySQL
		$values = array($db['base']);
		if (!$all) {
			$sql .= ' AND table_name LIKE ?';
			$values[] = $this->prefix . '%';
		}
		$stm = $this->bd->prepare($sql);
		$stm->execute($values);
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return $res[0];
	}

	public static function daoToEntry($listDAO) {
		$list = array();

		if (!is_array($listDAO)) {
			$listDAO = array($listDAO);
		}

		foreach ($listDAO as $key => $dao) {
			$entry = new FreshRSS_Entry(
				$dao['id_feed'],
				$dao['guid'],
				$dao['title'],
				$dao['author'],
				$dao['content'],
				$dao['link'],
				$dao['date'],
				$dao['is_read'],
				$dao['is_favorite'],
				$dao['tags']
			);
			if (isset($dao['id'])) {
				$entry->_id($dao['id']);
			}
			$list[] = $entry;
		}

		unset($listDAO);

		return $list;
	}
}
