<?php
/**
 * Nextcloud - spreedme
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Leon <leon@struktur.de>
 * @copyright struktur AG 2016
 */

namespace OCA\SpreedME\Controller;

use OCA\SpreedME\Errors\ErrorCodes;
use OCA\SpreedME\Helper\FileCounter;
use OCA\SpreedME\Helper\Helper;
use OCA\SpreedME\Security\Security;
use OCA\SpreedME\Settings\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\ILogger;
use OCP\IRequest;

class FileSharingController extends Controller {

	private $logger;
	private $rootFolder;

	public function __construct($appName, IRequest $request, $userId, ILogger $logger, IRootFolder $rootFolder) {
		parent::__construct($appName, $request);

		$this->logger = $logger;
		$this->rootFolder = $rootFolder;
	}

	private function validateRequest($tp) {
		if (!Helper::isUserLoggedIn() && !Security::validateTemporaryPassword(base64_decode($tp, true))) {
			return ErrorCodes::TEMPORARY_PASSWORD_INVALID;
		}

		if (!Helper::areFileTransferUploadsAllowed() || !Helper::doesServiceUserExist()) {
			return ErrorCodes::FILETRANSFER_DISABLED;
		}

		return null;
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function uploadAndShare($target, $tp) {
		$_response = array('success' => false);
		$target = stripslashes($target); // TODO(leon): Is this really required? Found it somewhere

		$err = $this->validateRequest($tp);
		if ($err !== null) {
			$_response['error'] = $err;
			return new DataResponse($_response);
		}

		try {
			$file = $this->request->getUploadedFile('file');
			if (empty($file)) {
				throw new \Exception('No file uploaded');
			}
			$fileName = $file['name'];
			if (is_array($fileName)) {
				// TODO(leon): We should support multiple file_s_
				throw new \Exception('Only a single file may be uploaded');
			}
			if ($file['error'] !== UPLOAD_ERR_OK) {
				throw new \Exception('Upload error: ' . $file['error']);
			}
			// TODO(leon): We don't need this check?
			if (!is_uploaded_file($file['tmp_name'])) {
				throw new \Exception('Uploaded file is not an uploaded file?');
			}
			if ($file['size'] > Helper::getServiceUserMaxUploadSize()) {
				throw new \Exception('Uploaded file is too big');
			}
			if (!Helper::hasAllowedFileExtension($fileName)) {
				throw new \Exception('Unsupported file extension');
			}

			$shareToken = $this->shareAndGetToken($file['name'], $file['tmp_name'], $target);

			$_response['token'] = $shareToken;
			$_response['success'] = true;
		} catch (\Exception $e) {
			$this->logger->logException($e, ['app' => Settings::APP_ID]);
			$code = $e->getCode();
			if ($code > 0) {
				$_response['error'] = $code;
			} else {
				$_response['error'] = ErrorCodes::FILETRANSFER_FAILED;
			}
		}

		return new DataResponse($_response);
	}

	private function doesFileExist($file, $cwd, $fsView) {
		$fsView->lockFile($file, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
		$exists = true;
		try {
			$cwd->get($file);
		} catch (\OCP\Files\NotFoundException $e) {
			$exists = false;
		} finally {
			$fsView->unlockFile($file, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
		}
		return $exists;
	}

	private function shareFile($file) {
		if (!method_exists(\OC::$server, 'getShareManager')) {
			return \OCP\Share::shareItem(
				'file',
				$file->getId(),
				\OCP\Share::SHARE_TYPE_LINK,
				null, /* shareWith */
				\OCP\Constants::PERMISSION_READ,
				null, /* itemSourceName */
				null/* expirationDate */
			);
		}
		// Nextcloud 9
		$manager = \OC::$server->getShareManager();
		$username = Settings::SPREEDME_SERVICEUSER_USERNAME;
		$shareType = \OCP\Share::SHARE_TYPE_LINK;
		$permissions = \OCP\Constants::PERMISSION_READ;
		$existingShares = $manager->getSharesBy($username, $shareType, $file, false, 1);
		// TODO(leon): Race condition here
		if (count($existingShares) > 0) {
			// We already have our share
			$share = $existingShares[0];
		} else {
			// Not shared yet, share it now
			$share = $manager->newShare();
			$share
				->setNode($file)
				->setShareType($shareType)
				->setPermissions($permissions)
				->setSharedBy($username);
			$manager->createShare($share);
		}
		return $share->getToken();
	}

	private function shareAndGetToken($fileName, $filePath, $target) {
		$serviceUserFolder = $this->rootFolder->getUserFolder(Settings::SPREEDME_SERVICEUSER_USERNAME);
		$uploadFolder = $serviceUserFolder
			->newFolder(Settings::SPREEDME_SERVICEUSER_UPLOADFOLDER)
			->newFolder($target);

		// Make sure the file system is initialized even for unauthenticated users
		\OC\Files\Filesystem::init(Settings::SPREEDME_SERVICEUSER_USERNAME, $serviceUserFolder->getPath());

		$fileExists = function ($fileName) use ($uploadFolder) {
			return $this->doesFileExist($fileName, $uploadFolder, \OC\Files\Filesystem::getView());
		};
		// Use file counter to determine unused file name
		// TODO(leon): Detect duplicates by hash
		$fileCounter = new FileCounter($fileName);
		do {
			$fileName = $fileCounter->next();
		} while ($fileExists($fileName));

		// TODO(leon): Data race here
		$newFile = $uploadFolder->newFile($fileName);
		$newFile->putContent(file_get_contents($filePath));

		return Helper::runAsServiceUser(function () use ($newFile) {
			return $this->shareFile($newFile);
		});
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function listShares($target, $tp) {
		$_response = array('success' => false);
		$target = stripslashes($target); // TODO(leon): Is this really required? Found it somewhere

		$err = $this->validateRequest($tp);
		if ($err !== null) {
			$_response['error'] = $err;
			return new DataResponse($_response);
		}

		try {
			$serviceUserFolder = $this->rootFolder->getUserFolder(Settings::SPREEDME_SERVICEUSER_USERNAME);
			$uploadFolder = $serviceUserFolder
				->newFolder(Settings::SPREEDME_SERVICEUSER_UPLOADFOLDER)
				->newFolder($target);

			$shares = array();
			foreach ($uploadFolder->getDirectoryListing() as $node) {
				if ($node->getType() !== 'file' || !Helper::hasAllowedFileExtension($node->getName())) {
					continue;
				}
				$shareToken = Helper::runAsServiceUser(function () use ($node) {
					return $this->shareFile($node);
				});
				$newShare = array(
					'name' => $node->getName(),
					'size' => $node->getSize(),
				);
				// Only expose token to logged-in users
				//if (Helper::isUserLoggedIn()) { // TODO(leon): Enable once we support lazy loading
				$newShare['token'] = $shareToken;
				//}
				$shares[] = $newShare;
			}

			$_response['shares'] = $shares;
			$_response['success'] = true;
		} catch (\Exception $e) {
			$this->logger->logException($e, ['app' => Settings::APP_ID]);
			$_response['error'] = ErrorCodes::FILETRANSFER_FAILED;
		}

		return new DataResponse($_response);
	}

}
