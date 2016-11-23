<?php
/*********************************************************************************
 * This file is part of Myddleware.

 * @package Myddleware
 * @copyright Copyright (C) 2013 - 2015  Stéphane Faure - CRMconsult EURL
 * @copyright Copyright (C) 2015 - 2016  Stéphane Faure - Myddleware ltd - contact@myddleware.com
 * @link http://www.myddleware.com	
 
 This file is part of Myddleware.
 
 Myddleware is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 Myddleware is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Myddleware.  If not, see <http://www.gnu.org/licenses/>.
*********************************************************************************/

namespace Myddleware\RegleBundle\Classes;

use Symfony\Bridge\Monolog\Logger; // Gestion des logs
use Symfony\Component\DependencyInjection\ContainerInterface as Container; // Accède aux services
use Doctrine\DBAL\Connection; // Connexion BDD
use Symfony\Component\HttpFoundation\Session\Session;
use Myddleware\RegleBundle\Classes\tools as MyddlewareTools; 
use Symfony\Component\Filesystem\Filesystem;

class jobcore  {
		
	public $id;
	public $message = '';
	public $createdJob = false;
	
	protected $container;
	protected $connection;
	protected $logger;
	protected $tools;
	
	protected $rule;
	protected $ruleId;
	protected $limit = 100;
	protected $logData;
	protected $start;
	protected $paramJob;
	protected $manual;
	protected $env;
	protected $nbDayClearJob = 7;

	public function __construct(Logger $logger, Container $container, Connection $dbalConnection) {				
		$this->logger = $logger; // gestion des logs symfony monolog
		$this->container = $container;
		$this->connection = $dbalConnection;
		$this->tools = new MyddlewareTools($this->logger, $this->container, $this->connection);	
		
		$this->env = $this->container->getParameter("kernel.environment");
		$this->setManual();
	}
		
	/*Permet de charger toutes les données de la règle (en paramètre)*/
	public function setRule($rule_name_slug) {
		try {
			include_once 'rule.php';
			
			// RECUPERE CONNECTEUR ID
		    $sqlRule = "SELECT * 
		    		FROM Rule 
		    		WHERE 
							rule_name_slug = :rule_name_slug
						AND rule_deleted = 0
					";
		    $stmt = $this->connection->prepare($sqlRule);
			$stmt->bindValue("rule_name_slug", $rule_name_slug);
		    $stmt->execute();	    
			$rule = $stmt->fetch(); // 1 row
			if (empty($rule['rule_id'])) {
				throw new \Exception ('Rule '.$rule_name_slug.' doesn\'t exist or is deleted.');
			}
			// Error if the rule is inactive and if we try to run it from a job (not manually)
			elseif(
					empty($rule['rule_active'])
				&& $this->manual == 0
			) {
				throw new \Exception ('Rule '.$rule_name_slug.' is inactive.');
			}
			
			$this->ruleId = $rule['rule_id'];
			
			// We instance the rule
			$param['ruleId'] = $this->ruleId;
			$param['jobId'] = $this->id;
			$param['limit'] = $this->limit;
			$param['manual'] = $this->manual;
			$this->rule = new rule($this->logger, $this->container, $this->connection, $param);
			return true;
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			$this->message .= $e->getMessage();
			return false;
		}	
	}

	// Permet de contrôler si un docuement de la même règle pour le même enregistrement n'est pas close
	public function createDocuments() {		
		if ($this->limit > 0) {
			$createDocuments = $this->rule->createDocuments();
			if (!empty($createDocuments['error'])) {
				$this->message .= print_r($createDocuments['error'],true);
			}
			if (!empty($createDocuments['count'])) {
				$this->limit = $this->limit-$createDocuments['count'];
				if ($this->limit < 0) {
					$this->limit = 0;
				}
				return $createDocuments['count'];
			}
			else {
				return 0;
			}
		}
		else {
			return 0;
		}
	}
	
	// Permet de contrôler si un docuement de la même règle pour le même enregistrement n'est pas close
	public function ckeckPredecessorDocuments() {
		$this->rule->ckeckPredecessorDocuments();
	}
	
	// Permet de filtrer les documents en fonction des filtres de la règle
	public function filterDocuments() {
		$this->rule->filterDocuments();
	}
	
	// Permet de contrôler si un docuement a une relation mais n'a pas de correspondance d'ID pour cette relation dans Myddleware
	public function ckeckParentDocuments() {
		$this->rule->ckeckParentDocuments();
	}
	
	// Permet de trasformer les documents
	public function transformDocuments() {
		$this->rule->transformDocuments();
	}
	
	// Permet de récupérer les données de la cible avant modification des données
	// 2 cas de figure : 
	//     - Le document est un document de modification
	//     - Le document est un document de création mais la règle a un paramètre de vérification des données pour ne pas créer de doublon
	public function getTargetDataDocuments() {
		$this->rule->getTargetDataDocuments();
	}

	// Ecriture dans le système source et mise à jour de la table document
	public function sendDocuments() {
		$sendDocuments = $this->rule->sendDocuments();	
		if (!empty($sendDocuments['error'])) {
			$this->message .= $sendDocuments['error'];
		}
	}
	
	// Ecriture dans le système source et mise à jour de la table document
	public function runError($limit, $attempt) {
		try {
			// Récupération de tous les flux en erreur ou des flux en attente (new) qui ne sont pas sur règles actives (règle child pour des règles groupées)
			$sqlParams = "	SELECT * 
							FROM Documents	
							WHERE 
									(
											global_status = 'Error'
										OR status = 'New'
									)
								AND attempt <= :attempt 
							ORDER BY attempt ASC, source_date_modified ASC	
							LIMIT $limit";
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->bindValue("attempt", $attempt);
		    $stmt->execute();	   				
			$documentsError = $stmt->fetchAll();
			if(!empty($documentsError)) {
				include_once 'rule.php';		
				foreach ($documentsError as $documentError) {
					$param['ruleId'] = $documentError['rule_id'];
					$param['jobId'] = $this->id;
					$rule = new rule($this->logger, $this->container, $this->connection, $param);
					$errorActionDocument = $rule->actionDocument($documentError['id'],'rerun');
					if (!empty($errorActionDocument)) {
						$this->message .= print_r($errorActionDocument,true);
					}
				}			
			}			
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			$this->message .= 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
		}
	}
	
	// Fonction permettant d'initialiser le job
	public function initJob($paramJob) {	
		$this->paramJob = $paramJob;
		$this->id = uniqid('', true);
		$this->start = microtime(true);
		
		// Check if a job is already running
		$sqlJobOpen = "SELECT * FROM Job WHERE job_status = 'Start' LIMIT 1";
		$stmt = $this->connection->prepare($sqlJobOpen);
		$stmt->execute();	    
		$job = $stmt->fetch(); // 1 row
		// Error if one job is still running
		if (!empty($job)) {
			$this->message .= $this->tools->getTranslation(array('messages', 'rule', 'another_task_running')).';'.$job['job_id'];
			return false;
		}
		// Create Job
		$insertJob = $this->insertJob();
		if ($insertJob) {
			$this->createdJob = true;
			return true;
		}
		else {
			$this->message .=  'Failed to create the Job in the database';		
			return false;
		}
	}
	
	// Permet de clôturer un job
	public function closeJob() {
		// Get job data
		$this->logData = $this->getLogData();

		// Update table job
		return $this->updateJob();
	}
	
	
	// Permet d'exécuter des jobs manuellement depuis Myddleware
	public function actionMassTransfer($event,$param) {
		if (in_array($event, array('rerun','cancel'))) { 
			// Pour ces 2 actions, l'event est le premier paramètre et ce sont les ids des cocuments qui sont envoyés dans le $param
			$paramJob[] = $event;
			$paramJob[] = implode(',',$param);
			return $this->runBackgroundJob('massaction',$paramJob);
		}
		else {
			return 'Action '.$event.' unknown. Failed to run this action. ';
		}
	}
	
	// Lancement d'un job manuellement en arrière plan 
	protected function runBackgroundJob($job,$param) {
		try{
			// Création d'un fichier temporaire
			$guid = uniqid();
			
			// Formatage des paramètres
			$params = implode(' ',$param);
			
			// récupération de l'exécutable PHP, par défaut c'est php
			$php = $this->container->getParameter('php');
			if (empty($php['executable'])) {
				$php['executable'] = 'php';
			}
				
			//Create your own folder in the cache directory
			$fileTmp = $this->container->getParameter('kernel.cache_dir') . '/myddleware/job/'.$guid.'.txt';		
			$fs = new Filesystem();
			try {
				$fs->mkdir(dirname($fileTmp));
			} catch (IOException $e) {
				throw new \Exception ("An error occured while creating your directory");
			}
			exec($php['executable'].' '.__DIR__.'/../../../../app/console myddleware:'.$job.' '.$params.' --env=prod  > '.$fileTmp.' &', $output);
			$cpt = 0;
			// Boucle tant que le fichier n'existe pas
			while (!file_exists($fileTmp)) {
				if($cpt >= 29) {
					throw new \Exception ('Failed to run the job.');
				}
				sleep(1);
				$cpt++;
			}
			
			// Boucle tant que l id du job n'est pas dans le fichier (écris en premier)
			$file = fopen($fileTmp, 'r');
			$idJob = fread($file, 23);
			fclose($file);
			while (empty($idJob)) {
				if($cpt >= 29) {
					throw new \Exception ('No task id given.');
				}
				sleep(1);
				$file = fopen($fileTmp, 'r');
				$idJob = fread($file, 23);
				fclose($file);
				$cpt++;
			}
			// Renvoie du message en session
			$session = new Session();
			$session->set( 'info', array('<a href="'.$this->container->get('router')->generate('task_view', array('id'=>$idJob)).'">'.$this->container->get('translator')->trans('session.task.msglink').'</a>. '.$this->container->get('translator')->trans('session.task.msginfo')));
			return $idJob;
		} catch (\Exception $e) {
			$session = new Session();
			$session->set( 'info', array($e->getMessage())); // Vous venez de lancer une nouvelle longue tâche. Elle est en cours de traitement.
			return false;
		}
	}

	// Fonction permettant d'annuler massivement des documents
	public function massAction($action,$idsDoc) {
		if (empty($idsDoc)) {
			$this->message .=  'No Ids in parameters of the job massAction.';		
			return false;
		}
		
		try {
			// Formatage du tableau d'idate
			$idsDocArray = explode(',',$idsDoc);	
			$queryIn = '(';
			foreach ($idsDocArray as $idDoc) {
				$queryIn .= "'".$idDoc."',";
			}
			$queryIn = rtrim($queryIn,',');
			$queryIn .= ')';
			
			// Création de la requête
			$sqlParams = "	SELECT 
								Documents.id,
								Documents.rule_id
							FROM Documents	
								INNER JOIN Rule
									ON Documents.rule_id = Rule.rule_id
							WHERE
									Documents.global_status IN ('Open','Error')
								AND Documents.id IN $queryIn
							ORDER BY Rule.rule_id";
			$stmt = $this->connection->prepare($sqlParams);
		    $stmt->execute();	   				
			$documents = $stmt->fetchAll();

			if(!empty($documents)) {
				include_once 'rule.php';	
				$param['ruleId'] = '';
				foreach ($documents as $document) {
					// Chargement d'une nouvelle règle que si nécessaire
					if ($param['ruleId'] != $document['rule_id']) {
						$param['ruleId'] = $document['rule_id'];
						$param['jobId'] = $this->id;
						$rule = new rule($this->logger, $this->container, $this->connection, $param);
					}
					$errorActionDocument = $rule->actionDocument($document['id'],$action);
					if (!empty($errorActionDocument)) {
						$this->message .= print_r($errorActionDocument,true);
					}
				}			
			}	
			else {
				$this->message .=  'No Document Open or in Error in parameters of the job massAction.';		
				return false;
			}
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
		}
	}
	
	public function getRules() {
		try {
			$sqlParams = "	SELECT rule_name_slug 
							FROM RuleOrder
								INNER JOIN Rule
									ON Rule.rule_id = RuleOrder.rule_id
							WHERE 
									Rule.rule_active = 1
								AND	Rule.rule_deleted = 0
							ORDER BY RuleOrder.rod_order ASC";
			$stmt = $this->connection->prepare($sqlParams);
		    $stmt->execute();	   				
			$rules = $stmt->fetchAll();
			if(!empty($rules)) {	
				foreach ($rules as $rule) {
					$ruleOrder[] = $rule['rule_name_slug'];
				}			
			}
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			return false;
		}
		if (empty($ruleOrder)) {
			return null;
		}
		return $ruleOrder;
	}
	
	// Fonction permettant de définir un ordre dans le lancement des règles
	public function orderRules() {
		$this->connection->beginTransaction(); // -- BEGIN TRANSACTION
		 try {
			// Récupération de toutes les règles avec leurs règles liées (si plusieurs elles sont toutes au même endroit)
			// Si la règle n'a pas de relation on initialise l'ordre à 1 sinon on met 99
			$sql = "SELECT
						Rule.rule_id,
						GROUP_CONCAT(RuleRelationShips.rrs_field_id SEPARATOR ';') rrs_field_id,
						IF(RuleRelationShips.rrs_field_id IS NULL, '1', '99') rule_order
					FROM Rule
						LEFT OUTER JOIN RuleRelationShips
							ON Rule.rule_id = RuleRelationShips.rule_id
					GROUP BY Rule.rule_id";
			$stmt = $this->connection->prepare($sql);
			$stmt->execute();	    
			$rules = $stmt->fetchAll(); 	
			if (!empty($rules)) {
				// Création d'un tableau en clé valeur et sauvegarde d'un tableau de référence
				$ruleKeyVakue = array();
				foreach ($rules as $rule) {
					$ruleKeyVakue[$rule['rule_id']] = $rule['rule_order'];
					$rulesRef[$rule['rule_id']] = $rule;
				}	
				
				// On calcule les priorité tant que l'on a encore des priorité 99
				// On fait une condition sur le $i pour éviter une boucle infinie
				$i = 0;
				while ($i < 20 && array_search('99', $ruleKeyVakue)!==false) {
					$i++;
					// Boucles sur les régles
					foreach ($rules as $rule) {
						$order = 0;
						// Si on est une règle sans ordre
						if($rule['rule_order'] == '99') {
							// Récupération des règles liées et recherche dans le tableau keyValue
							$rulesLink = explode(";", $rule['rrs_field_id']);
							foreach ($rulesLink as $ruleLink) {
								if(
										!empty($ruleKeyVakue[$ruleLink])
									&&	$ruleKeyVakue[$ruleLink] > $order
								) {
									$order = $ruleKeyVakue[$ruleLink];
								}
							}
							// Si toutes les règles trouvées ont une priorité autre que 99 alors on affecte à la règle la piorité +1 dans les tableaux de références
							if ($order < 99) {
								$ruleKeyVakue[$rule['rule_id']] = $order+1;
								$rulesRef[$rule['rule_id']]['rule_order'] = $order+1;
							}
						}
					}	
					$rules = $rulesRef;		
				}
				
				// On vide la table RuleOrder
				$sql = "DELETE FROM RuleOrder";
				$stmt = $this->connection->prepare($sql);
				$stmt->execute();	
				
				//Mise à jour de la table
				$insert = "INSERT INTO RuleOrder VALUES ";
				foreach ($ruleKeyVakue as $key => $value) {
					$insert .= "('$key','$value'),";
				}
				// Suppression de la dernière virgule  
				$insert = rtrim($insert,','); 
				$stmt = $this->connection->prepare($insert);
				$stmt->execute();		
			} 
			$this->connection->commit(); // -- COMMIT TRANSACTION
		} catch (\Exception $e) {
			$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
			$this->message .= 'Failed to update table RuleOrder : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
			$this->logger->error($this->message);
			return false;
		}	 
	}
	
	public function generateTemplate($nomTemplate,$descriptionTemplate,$rulesId) {
		include_once 'template.php';
		$templateString = '';	
		if (!empty($rulesId)) {
			$first = true;
			$guidTemplate = uniqid();
			$template = new template($this->logger, $this->container, $this->connection);
			foreach($rulesId as $ruleId) {
				if ($first === true) {
					$templateString .= $template->generateTemplateHeader($nomTemplate,$descriptionTemplate,$ruleId,$guidTemplate);
					$first = false;
				}
				$generateTemplate = $template->generateTemplateRule($ruleId,$guidTemplate);
				if (empty($generateTemplate['error'])) {
					$templateString .= $generateTemplate['sql'];
				}
				else {
					return array('done' => false, 'error' => $generateTemplate['error']);
				}
			}
			// Ecriture du fichier
			$file = __DIR__.'/../Templates/'.$nomTemplate.'.sql';
			$fp = fopen($file, 'wb');
			if ($fp === false) {
				return array('done' => false, 'error' => 'Failed to open the file');
			}
			$fw = fwrite($fp,utf8_encode($templateString));
			if ($fw === false) {
				return array('done' => false, 'error' => 'Failed to write into the file');
			}
			else {
				return array('done' => true, 'error' => '');
			}
		}
		return $templateString;
	}
	
	public function refreshTemplate() {
		include_once 'template.php';
		$template = new template($this->logger, $this->container, $this->connection);
		return $template->refreshTemplate();
	}
	
	// Permet d'indiquer que le job est lancé manuellement
	protected function setManual() {
		if ($this->env == 'background') {
			$this->manual = 0;
		}
		else {
			$this->manual = 1;
		}
	}
	
	// Permet d'indiquer que le job est lancé manuellement
	public function setConfigValue($name,$value) {
		$this->connection->beginTransaction(); // -- BEGIN TRANSACTION suspend auto-commit
		// Récupération de la valeur de la config
		$select = "	SELECT * FROM Config WHERE conf_name = '$name'";
		$stmt = $this->connection->prepare($select);
		$stmt->execute();	   				
		$config = $stmt->fetch();
		try {
			// S'il n'existe pas on fait un INSERT sinon un UPDATE
			if (empty($config)) {
				$sqlParams = "INSERT INTO Config (conf_name, conf_value) VALUES (:name, :value)";
			}
			else {
				$sqlParams = "UPDATE Config SET conf_value = :value WHERE conf_name = :name";
			}
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->bindValue("value", $value);
			$stmt->bindValue("name", $name);
			$stmt->execute();	
			$this->connection->commit(); // -- COMMIT TRANSACTION
		} catch (\Exception $e) {
			$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
			$this->logger->error( 'Failed to update the config name '.$name.' whithe the value '.$value.' : '.$e->getMessage() );
			echo 'Failed to update the config name '.$name.' whithe the value '.$value.' : '.$e->getMessage() ;
			return false;
		}		
		return true;
	}
	
	// Permet d'indiquer que le job est lancé manuellement
	public function getConfigValue($name) {
		// Récupération de la valeur de la config
		$select = "	SELECT * FROM Config WHERE conf_name = '$name'";
		$stmt = $this->connection->prepare($select);
		$stmt->execute();	   				
		$config = $stmt->fetch();
		return $config['conf_value'];
	}


	// Send notification to receive statistique about myddleware data transfer
	public function sendNotification() {
		try {
			// Get the email address for notification
			$contactMail = $this->container->getParameter('notification_emailaddress');
			if (empty($contactMail)) {
				throw new \Exception ('No email address for notification. Please add the parameter notification_emailaddress in the file app/config/config_background.yml.');
			}
			
			// Write the introduction
			$textMail = $this->tools->getTranslation(array('email_notification', 'hello')).chr(10).chr(10).$this->tools->getTranslation(array('email_notification', 'introduction')).chr(10);

			// Récupération du nombre de données transférées depuis la dernière notification. On en compte qu'une fois les erreurs
			$sqlParams = "	SELECT
								count(distinct Log.doc_id) cpt,
								Documents.global_status
							FROM Job
								INNER JOIN Log
									ON Log.job_id = Job.job_id
								INNER JOIN Rule
									ON Log.rule_id = Rule.rule_id
								INNER JOIN Documents
									ON Documents.id = Log.doc_id
							WHERE
									Job.job_begin BETWEEN (SELECT MAX(job_begin) FROM Job WHERE job_param = 'notification' AND job_end >= job_begin) AND NOW()
								AND (
										Documents.global_status != 'Error'
									OR (
											Documents.global_status = 'Error'
										AND Documents.date_modified BETWEEN (SELECT MAX(job_begin) FROM Job WHERE job_param = 'notification' AND job_end >= job_begin) AND NOW()
									)
								)
							GROUP BY Documents.global_status";
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->execute();	   				
			$cptLogs = $stmt->fetchAll();
			$job_open = 0;
			$job_close = 0;
			$job_cancel = 0;
			$job_error = 0;
			if (!empty($cptLogs)) {
				foreach ($cptLogs as $cptLog) {
					switch ($cptLog['global_status']) {
						case 'Open':
							$job_open = $cptLog['cpt'];
							break;
						case 'Error':
							$job_error = $cptLog['cpt'];
							break;
						case 'Close':
							$job_close = $cptLog['cpt'];
							break;
						case 'Cancel':
							$job_cancel = $cptLog['cpt'];
							break;
					}
				}
			}			
			$textMail .= $this->tools->getTranslation(array('email_notification', 'transfer_success')).' '.$job_close.chr(10);
			$textMail .= $this->tools->getTranslation(array('email_notification', 'transfer_error')).' '.$job_error.chr(10);
			$textMail .= $this->tools->getTranslation(array('email_notification', 'transfer_open')).' '.$job_open.chr(10);	
			
			// Récupération des règles actives
			$sqlParams = "	SELECT * 
							FROM Rule
							WHERE
									Rule.rule_active = 1
								AND	Rule.rule_deleted = 0
			";
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->execute();	   				
			$activeRules = $stmt->fetchAll();
			if (!empty($activeRules)) {
				$textMail .= chr(10).$this->tools->getTranslation(array('email_notification', 'active_rule')).chr(10);
				foreach ($activeRules as $activeRule) {
					$textMail .= " - ".$activeRule['rule_name']." v".$activeRule['rule_version'].chr(10);
				}
			}
			else {
				$textMail .= chr(10).$this->tools->getTranslation(array('email_notification', 'no_active_rule')).chr(10);
			}
			
			
			// Get errors since the last notification
			if ($job_error > 0) {
				$sqlParams = "	SELECT
									Log.log_created,
									Log.log_msg,
									Log.doc_id,
									Rule.rule_name
								FROM Job
									INNER JOIN Log
										ON Log.job_id = Job.job_id
									INNER JOIN Rule
										ON Log.rule_id = Rule.rule_id
									INNER JOIN Documents
										ON Documents.id = Log.doc_id
								WHERE
										Job.job_begin BETWEEN (SELECT MAX(job_begin) FROM Job WHERE job_param = 'notification' AND job_end >= job_begin) AND NOW()
									AND Documents.date_modified BETWEEN (SELECT MAX(job_begin) FROM Job WHERE job_param = 'notification' AND job_end >= job_begin) AND NOW()
									AND Documents.global_status = 'Error'
								ORDER BY Log.log_created ASC
								LIMIT 100	";
				$stmt = $this->connection->prepare($sqlParams);
				$stmt->execute();	   				
				$logs = $stmt->fetchAll();

				if (count($logs) == 100) {
					$textMail .= chr(10).chr(10).$this->tools->getTranslation(array('email_notification', '100_first_erros')).chr(10);
				}
				else  {
					$textMail .= chr(10).chr(10).$this->tools->getTranslation(array('email_notification', 'error_list')).chr(10);
				}
				foreach ($logs as $log) {
					$textMail .= " - Règle $log[rule_name], id transfert $log[doc_id], le $log[log_created] : $log[log_msg]".chr(10);
				}
			}
			
			$textMail .= chr(10).$this->tools->getTranslation(array('email_notification', 'best_regards')).chr(10).$this->tools->getTranslation(array('email_notification', 'signature'));
			$message = \Swift_Message::newInstance()
				->setSubject($this->tools->getTranslation(array('email_notification', 'subject')))
 				->setFrom('no-reply@myddleware.com')
				->setTo($contactMail)
				->setBody($textMail)
			;
			$send = $this->container->get('mailer')->send($message);
			if (!$send) {
				$this->logger->error('Failed to send email : '.$textMail.' to '.$contactMail);	
				throw new \Exception ('Failed to send email : '.$textMail.' to '.$contactMail);
			}
			return true;
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			$this->message .= $e->getMessage();
			return false;							
		}
	}
	
	// Permet de supprimer toutes les données des tabe source, target et history en fonction des paramètre de chaque règle
	public function clearData() {
		//Get the table list 
		$sqlTables = "SHOW TABLES";
		$stmt = $this->connection->prepare($sqlTables);
		$stmt->execute();	   				
		$tablesQuery = $stmt->fetchAll();
		foreach ($tablesQuery as $key => $table) {
			$tables[] = current($table);
		}
	
		// Récupération de chaque règle et du paramètre de temps de suppression
		$sqlParams = "	SELECT 
							Rule.rule_id,
							Rule.rule_name_slug,
							Rule.rule_version,
							RuleParams.rulep_value days
						FROM Rule
							INNER JOIN RuleParams
								ON Rule.rule_id = RuleParams.rule_id
						WHERE
							RuleParams.rulep_name = 'delete'";
		$stmt = $this->connection->prepare($sqlParams);
		$stmt->execute();	   				
		$rules = $stmt->fetchAll();
		
		if (!empty($rules)) {
			// Boucle sur toutes les règles
			foreach ($rules as $rule) {
				$tableId = array();
				if (in_array('z_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_source',$tables)) {
					$tableId['z_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_source'] = 'id_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_source';
				}
				if (in_array('z_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_target',$tables)) {
					$tableId['z_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_target'] = 'id_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_target';
				}
				if (in_array('z_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_history',$tables)) {
					$tableId['z_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_history'] = 'id_'.$rule['rule_name_slug'].'_'.$rule['rule_version'].'_history';
				}
				
				if (!empty($tableId)) {
					foreach ($tableId as $table => $id) {
						$this->connection->beginTransaction();						
						try {
							$deleteSource = "
								DELETE $table
								FROM Documents
									INNER JOIN $table
										ON Documents.id = $table.$id
								WHERE 
										Documents.rule_id = '$rule[rule_id]'
									AND Documents.global_status IN ('Close','Cancel')
									AND DATEDIFF(CURRENT_DATE( ),Documents.date_modified) >= $rule[days]
							";							
							$stmt = $this->connection->prepare($deleteSource);
							$stmt->execute();
							$this->connection->commit(); // -- COMMIT TRANSACTION
						} catch (\Exception $e) {
							$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
							$this->message .= 'Failed to clear data for the rule '.$rule['rule_id'].' : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
							$this->logger->error($this->message);	
						}
					}
				}
				
				// Delete log for these rule
				$this->connection->beginTransaction();						
				try {
					$deleteLog = "
						DELETE Log
						FROM Log
							INNER JOIN Documents
								ON Log.doc_id = Documents.id
						WHERE 
								Log.rule_id = '$rule[rule_id]'
							AND Log.log_msg IN ('Status : Filter_OK','Status : Predecessor_OK','Status : Relate_OK','Status : Transformed','Status : Ready_to_send')	
							AND Documents.global_status IN ('Close','Cancel')
							AND DATEDIFF(CURRENT_DATE( ),Documents.date_modified) >= $rule[days]
					";						
					$stmt = $this->connection->prepare($deleteLog);
					$stmt->execute();
					$this->connection->commit(); // -- COMMIT TRANSACTION
				} catch (\Exception $e) {
					$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
					$this->message .= 'Failed to clear logs for the rule '.$rule['rule_id'].' : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
					$this->logger->error($this->message);	
				}
			}
		}
		$this->clearJob();
	}
	
	// Permet de vider les log vides
	protected function clearJob() {
		$this->connection->beginTransaction();			
		try {
			// Suppression des jobs de transfert vide et des autres jobs qui datent de plus de nbDayClearJob jours
			$deleteJob = " 	DELETE FROM Job
							WHERE 
									job_status = 'End'
								AND (
										(
											job_param NOT IN ('cleardata', 'backup', 'notification')
											AND job_message IN ('', 'Another job is running. Failed to start job. ')
											AND job_open = 0
											AND job_close = 0
											AND job_cancel = 0
											AND job_error = 0
										)
									OR 	(
											job_param IN ('cleardata', 'backup', 'notification')
											AND job_message = ''
											AND DATEDIFF(CURRENT_DATE( ),job_end) > '$this->nbDayClearJob'
										)
									)
			";	
			$stmt = $this->connection->prepare($deleteJob);
			$stmt->execute();
			$this->connection->commit(); // -- COMMIT TRANSACTION
		} catch (\Exception $e) {
			$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
			$this->message .= 'Failed to clear data in table Job: '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
			$this->logger->error($this->message);	
		}
	}
	
 	// Récupération des données du job
	protected function getLogData() {
		try {
			// Récupération du nombre de document envoyé et en erreur pour ce job
			$this->logData['Close'] = 0;
			$this->logData['Cancel'] = 0;
			$this->logData['Open'] = 0;
			$this->logData['Error'] = 0;
			$this->logData['paramJob'] = $this->paramJob;
			$sqlParams = "	SELECT 
								count(distinct Documents.id) nb,
								Documents.global_status
							FROM Log
								INNER JOIN Documents
									ON Log.doc_id = Documents.id
							WHERE
								Log.job_id = :id
							GROUP BY Documents.global_status";
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->bindValue("id", $this->id);
		    $stmt->execute();	   				
			$data = $stmt->fetchAll();
			if(!empty($data)) {
				foreach ($data as $row) {
					if($row['global_status'] == 'Close' ) {
						$this->logData['Close'] = $row['nb'];
					}
					elseif($row['global_status'] == 'Error' ) {
						$this->logData['Error'] = $row['nb'];	
					}
					elseif($row['global_status'] == 'Cancel' ) {
						$this->logData['Cancel'] = $row['nb'];	
					}
					elseif($row['global_status'] == 'Open' ) {
						$this->logData['Open'] = $row['nb'];	
					}
				}			
			}	
			
			// Récupération des solutions du job
			$sqlParams = "	SELECT 
								Connector_target.sol_id sol_id_target,
								Connector_source.sol_id sol_id_source
							FROM (SELECT DISTINCT rule_id FROM Log WHERE job_id = :id) rule_job
								INNER JOIN Rule
									ON rule_job.rule_id = Rule.rule_id
								INNER JOIN Connector Connector_source
									ON Connector_source.conn_id = Rule.conn_id_source
								INNER JOIN Connector Connector_target
									ON Connector_target.conn_id = Rule.conn_id_target";
			$stmt = $this->connection->prepare($sqlParams);
			$stmt->bindValue("id", $this->id);
		    $stmt->execute();	   				
			$solutions = $stmt->fetchAll();
			$this->logData['solutions'] = '';
			if (!empty($solutions)) {
				foreach ($solutions as $solution) {
					$concatSolution[] = $solution['sol_id_target'];
					$concatSolution[] = $solution['sol_id_source'];
				}
				$concatSolutions = array_unique($concatSolution);
				// Mise au format pour la liste multi de Sugar
				$concatSolutions = '^'.implode("^,^", $concatSolutions).'^';
				$this->logData['solutions'] = $concatSolutions;
			}
			
			// Récupération de la durée du job
			$time_end = microtime(true);
			$this->logData['duration'] = round($time_end - $this->start,2);
			
			// récupération de l'id du job
			$this->logData['myddlewareId'] = $this->id;
					
			// Indique si le job est lancé manuellement ou non
			$this->logData['Manual'] = $this->manual;
			
			// Récupération des erreurs
			$this->logData['jobError'] = $this->message;
		} catch (\Exception $e) {
			$this->logger->error( 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			$this->logData['jobError'] = 'Error : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';
		}
		return $this->logData;
	}
	
	// Mise à jour de la table Job
	protected function updateJob() {
		$this->connection->beginTransaction(); // -- BEGIN TRANSACTION
		try {
			$close = $this->logData['Close'];
			$cancel = $this->logData['Cancel'];
			$open = $this->logData['Open'];
			$error = $this->logData['Error'];
			$now = gmdate('Y-m-d H:i:s');
			$message = $this->message;
			if (!empty($this->message)) {
				$message = htmlspecialchars($this->message);
			}
			$query_header = "UPDATE Job 
							SET 
								job_end = :now, 
								job_status = 'End', 
								job_close = :close, 
								job_cancel = :cancel, 
								job_open = :open, 
								job_error = :error, 
								job_message = :message
							WHERE job_id = :id"; 	
			$stmt = $this->connection->prepare($query_header);
			$stmt->bindValue("now", $now);
			$stmt->bindValue("close", $close);
			$stmt->bindValue("cancel", $cancel);
			$stmt->bindValue("open", $open);
			$stmt->bindValue("error", $error);
			$stmt->bindValue("message", $message);
			$stmt->bindValue("id", $this->id);
			$stmt->execute();
			$this->connection->commit(); // -- COMMIT TRANSACTION			
		} catch (\Exception $e) {
			$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
			$this->logger->error( 'Failed to update Job : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			$this->message .= 'Failed to update Job : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';		
			return false;
		}
		return true;
	}
	
	protected function insertJob() {
		$this->connection->beginTransaction(); // -- BEGIN TRANSACTION
		try {
			$now = gmdate('Y-m-d H:i:s');
			$query_header = "INSERT INTO Job (job_id, job_begin, job_status, job_param, job_manual) VALUES ('$this->id', '$now', 'Start', '$this->paramJob', '$this->manual')";
			$stmt = $this->connection->prepare($query_header);
			$stmt->execute();
			$this->connection->commit(); // -- COMMIT TRANSACTION
		} catch (\Exception $e) {
			$this->connection->rollBack(); // -- ROLLBACK TRANSACTION
			$this->logger->error( 'Failed to create Job : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )' );
			$this->message .=  'Failed to create Job : '.$e->getMessage().' '.__CLASS__.' Line : ( '.$e->getLine().' )';		
			return false;
		}
		return true;
	}
	
}


/* * * * * * * *  * * * * * *  * * * * * * 
	si custom file exist alors on fait un include de la custom class
 * * * * * *  * * * * * *  * * * * * * * */
$file = __DIR__.'/../Custom/Classes/job.php';
if(file_exists($file)){
	require_once($file);
}
else {
	//Sinon on met la classe suivante
	class job extends jobcore {
		
	}
}
?>