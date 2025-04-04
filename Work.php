<?php

	namespace fastwhale\yii2\weWork;

	require_once "components/errorInc/error.inc.php";

	use fastwhale\yii2\weWork\components\BaseWork;
	use fastwhale\yii2\weWork\src\dataStructure\Agent;
	use fastwhale\yii2\weWork\src\dataStructure\Batch;
	use fastwhale\yii2\weWork\src\dataStructure\BatchJobArgs;
	use fastwhale\yii2\weWork\src\dataStructure\Department;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContact;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactBatchGetByUser;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactBehavior;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactGroupChat;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactMsgTemplate;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactRemark;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactTag;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactTagGroup;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactUnAssignUser;
	use fastwhale\yii2\weWork\src\dataStructure\ExternalContactWay;
	use fastwhale\yii2\weWork\src\dataStructure\LinkedcorpMessage;
	use fastwhale\yii2\weWork\src\dataStructure\Message;
	use fastwhale\yii2\weWork\src\dataStructure\MsgAuditCheckAgree;
	use fastwhale\yii2\weWork\src\dataStructure\Tag;
	use fastwhale\yii2\weWork\src\dataStructure\User;
	use fastwhale\yii2\weWork\src\dataStructure\UserDetailByUserTicket;
	use fastwhale\yii2\weWork\src\dataStructure\UserInfoByCode;
	use fastwhale\yii2\weWork\components\HttpUtils;
	use fastwhale\yii2\weWork\components\Utils;
	use yii\base\Event;
	use yii\base\InvalidParamException;

	class Work extends BaseWork
	{
		/**
		 * 每个企业都拥有唯一的corpid，获取此信息可在管理后台“我的企业”－“企业信息”下查看“企业ID”（需要有管理员权限）
		 *
		 * @var string
		 */
		public $corpid;
		/**
		 * secret是企业应用里面用于保障数据安全的“钥匙”，每一个应用都有一个独立的访问密钥，为了保证数据的安全，secret务必不能泄漏。
		 * 自建应用secret。在管理后台->“应用与小程序”->“应用”->“自建”，点进某个应用，即可看到。
		 * 基础应用secret。某些基础应用（如“审批”“打卡”应用），支持通过API进行操作。在管理后台->“应用与小程序”->“应用->”“基础”，点进某个应用，点开“API”小按钮，即可看到。
		 * 通讯录管理secret。在“管理工具”-“通讯录同步”里面查看（需开启“API接口同步”）；
		 * 外部联系人管理secret。在“客户联系”栏，点开“API”小按钮，即可看到。
		 *
		 * @var string
		 */
		public $secret;
		/**
		 * access_token是企业后台去企业微信的后台获取信息时的重要票据，由corpid和secret产生。所有接口在通信时都需要携带此信息用于验证接口的访问权限
		 *
		 * @var string
		 */
		public $access_token;
		/**
		 * 凭证的有效时间（秒）
		 *
		 * @var string
		 */
		public $access_token_expire;
		/**
		 * 用于计算签名，由英文或数字组成且长度不超过32位的自定义字符串。
		 *
		 * @var string
		 */
		protected $token;
		/**
		 * 用于消息内容加密，由英文或数字组成且长度为43位的自定义字符串。
		 *
		 * @var string
		 */
		protected $encodingAesKey;

		/**
		 * 数据缓存前缀
		 *
		 * @var string
		 */
		protected $cachePrefix = 'cache_work_wx';

		/**
		 * 企业进行自定义开发调用, 请传参 corpid + secret, 不用关心accesstoken，本类会自动获取并刷新
		 *
		 * @throws \ParameterError
		 */
		public function init ()
		{
			Utils::checkNotEmptyStr($this->corpid, 'corpid');
			Utils::checkNotEmptyStr($this->secret, 'secret');
		}

		/**
		 * 获取缓存键值
		 *
		 * @param $name
		 *
		 * @return string
		 */
		protected function getCacheKey ($name)
		{
			return $this->cachePrefix . '_' . $this->corpid . '_' . $name;
		}

		/**
		 * 获取 accesstoken 不用主动调用
		 *
		 * @param bool $force
		 *
		 * @return string|void
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function GetAccessToken ($force = false)
		{
			$time = time();
			if (!Utils::notEmptyStr($this->access_token) || $this->access_token_expire < $time || $force) {
				$result = !Utils::notEmptyStr($this->access_token) && !$force ? $this->getCache("access_token", false) : false;
				if ($result === false) {
					$result = $this->RefreshAccessToken();
				} else {
					if ($result['expire'] < $time) {
						$result = $this->RefreshAccessToken();
					}
				}

				$this->SetAccessToken($result);
			}

			return $this->access_token;
		}

		/**
		 * 更新 accesstoken
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		protected function RefreshAccessToken ()
		{
			if (!Utils::notEmptyStr($this->corpid) || !Utils::notEmptyStr($this->secret)) {
				throw new \ParameterError("invalid corpid or secret");
			}

			$time = time();
			$this->_HttpCall(self::GET_TOKEN, 'GET', ['corpid' => $this->corpid, 'corpsecret' => $this->secret]);

			$this->repJson['expire'] = $time + $this->repJson["expires_in"];
			$this->setCache('access_token', $this->repJson, $this->repJson['expires_in']);

			return $this->repJson;
		}

		/**
		 * 设置 accesstoken
		 *
		 * @param array $accessToken
		 *
		 * @throws InvalidParamException
		 */
		public function SetAccessToken (array $accessToken)
		{
			if (!isset($accessToken['access_token'])) {
				throw new InvalidParamException('The work access_token must be set.');
			} elseif (!isset($accessToken['expire'])) {
				throw new InvalidParamException('Work access_token expire time must be set.');
			}
			$this->access_token        = $accessToken['access_token'];
			$this->access_token_expire = $accessToken['expire'];
		}

		protected function GetOauth2Url ($appid, $redirectUri, $state, $scope = self::SNSAPI_BASE)
		{
			return "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$appid}&redirect_uri={$redirectUri}&response_type=code&scope={$scope}&state={$state}#wechat_redirect";
		}

		/* 成员管理 */
		/**
		 * 创建成员
		 *
		 * @link https://work.weixin.qq.com/api/doc/90000/90135/90195
		 *
		 * @param User $user
		 *
		 * @return array|null
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function userCreate (User $user)
		{
			User::CheckUserCreateArgs($user);
			$args = Utils::Object2Array($user);

			self::_HttpCall(self::USER_CREATE, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 读取成员
		 *
		 * @link https://work.weixin.qq.com/api/doc/90000/90135/90196
		 *
		 * @param string $userId
		 *
		 * @return User
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function userGet ($userId)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::USER_GET, 'GET', ['userid' => $userId]);

			return User::parseFromArray($this->repJson);
		}

		/**
		 * 更新成员
		 *
		 * @link https://work.weixin.qq.com/api/doc/90000/90135/90197
		 *
		 * @param User $user
		 *
		 * @return array|null
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function userUpdata (User $user)
		{
			User::CheckUserUpdateArgs($user);
			$args = Utils::Object2Array($user);
			self::_HttpCall(self::USER_UPDATE, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 删除成员
		 *
		 * @link https://work.weixin.qq.com/api/doc/90000/90135/90198
		 *
		 * @param string $userId
		 *
		 * @return array|null
		 *
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function userDelete ($userId)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::USER_DELETE, 'GET', ['userid' => $userId]);

			return $this->repJson;

		}

		public function userBatchDelete (array $userIdList)
		{
			User::CheckUserBatchDeleteArgs($userIdList);
			self::_HttpCall(self::USER_BATCH_DELETE, 'POST', ['useridlist' => $userIdList]);

			return $this->repJson;
		}

		public function userSimpleList ($deparmentId, $fetchChild = 0)
		{
			self::_HttpCall(self::USER_SIMPLE_LIST, 'GET', ['department_id' => $deparmentId, 'fetch_child' => $fetchChild]);

			return User::Array2UserList($this->repJson);
		}

		public function userList ($deparmentId, $fetchChild = 0)
		{
			self::_HttpCall(self::USER_LIST, 'GET', ['department_id' => $deparmentId, 'fetch_child' => $fetchChild]);

			return User::Array2UserList($this->repJson);
		}

		public function userConvertToOpenid ($userId, &$openid)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::USER_CONVERT_TO_OPENID, 'POST', ['userid' => $userId]);

			$openid = Utils::arrayGet($this->repJson, 'openid');
		}

		public function userConvertTouserId ($openid, &$userId)
		{
			Utils::checkNotEmptyStr($openid, 'openid');
			self::_HttpCall(self::USER_CONVERT_TO_USERID, 'POST', ['openid' => $openid]);

			$userId = Utils::arrayGet($this->repJson, 'userid');
		}

		public function externalConvertToOpenid ($externalUserid, &$openid)
		{
			Utils::checkNotEmptyStr($externalUserid, 'external_userid');
			self::_HttpCall(self::EXTERNAL_CONTACT_CONVER_TO_OPENID, 'POST', ['external_userid' => $externalUserid]);

			$openid = Utils::arrayGet($this->repJson, 'openid');
		}

		public function userAuthSuccess ($userId)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::USER_AUTHSUCC, 'GET', ['userid' => $userId]);

			return $this->repJson;
		}

		private function getInvalidList (&$invalidUserIdList, &$invalidPartyIdList, &$invalidTagIdList)
		{
			$invalidUserIdList = Utils::arrayGet($this->repJson, "invaliduser");
			if (strpos($invalidUserIdList, '|') !== false) {
				$invalidUserIdList = explode('|', $invalidUserIdList);
			}

			$invalidPartyIdList = Utils::arrayGet($this->repJson, "invalidparty");
			if (strpos($invalidPartyIdList, '|') !== false) {
				$invalidPartyIdList = explode('|', $invalidPartyIdList);
			}

			$invalidTagIdList = Utils::arrayGet($this->repJson, "invalidtag");
			if (strpos($invalidTagIdList, '|') !== false) {
				$invalidTagIdList = explode('|', $invalidTagIdList);
			}
		}

		public function batchInvite ($userIdList = NULL, $partyIdList = NULL, $tagIdList = NULL, &$invalidUserIdList, &$invalidPartyIdList, &$invalidTagIdList)
		{
			if (!Utils::notEmptyArray($userIdList) && !Utils::notEmptyArray($partyIdList) && !Utils::notEmptyArray($tagIdList)) {
				throw new \QyApiError('input can not be all null');
			}

			$args = [];

			if (Utils::notEmptyArray($userIdList)) {
				$args['user'] = $userIdList;
			}

			if (Utils::notEmptyArray($partyIdList)) {
				$args['party'] = $partyIdList;
			}

			if (Utils::notEmptyArray($tagIdList)) {
				$args['tag'] = $tagIdList;
			}

			self::_HttpCall(self::BATCH_INVITE, 'POST', $args);

			$this->getInvalidList($invalidUserIdList, $invalidPartyIdList, $invalidTagIdList);
		}

		public function corpGetJoinQrcode (&$joinQrcode, $sizeType = NULL)
		{
			$args = [];
			if (!is_null($sizeType)) {
				$args['size_type'] = $sizeType;
			}

			self::_HttpCall(self::CORP_GET_JOIN_QECODE, 'GET', $args);

			$joinQrcode = Utils::arrayGet($this->repJson, 'join_qrcode');
		}

		public function getMobileHashcode (&$mobile, $state = '')
		{
			Utils::checkIsUInt($mobile, 'mobile');
			self::_HttpCall(self::GET_MOBILE_HASHCODE, 'POST', ['mobile' => $mobile, 'state' => $state]);

			$mobile = Utils::arrayGet($this->repJson, 'hashcode');
		}

		/* 部门管理 */
		public function departmentCreate (Department $department, &$departmentId)
		{
			Department::CheckDepartmentCreateArgs($department);
			$args = Department::department2Array($department);
			self::_HttpCall(self::DEPARTMENT_CREATE, 'POST', $args);

			$departmentId = Utils::arrayGet($this->repJson, 'id');
		}

		public function departmentUpdate (Department $department)
		{
			Department::CheckDepartmentUpdateArgs($department);
			$args = Department::department2Array($department);
			self::_HttpCall(self::DEPARTMENT_UPDATE, 'POST', $args);

			return $this->repJson;
		}

		public function departmentDelete ($departmentId)
		{
			Utils::checkIsUInt($departmentId, 'departmentid');
			self::_HttpCall(self::DEPARTMENT_DELETE, 'GET', ['id' => $departmentId]);

			return $this->repJson;
		}

		public function departmentList ($departmentId = NULL)
		{
			$args = [];
			if (!is_null($departmentId)) {
				$args['id'] = $departmentId;
			}
			self::_HttpCall(self::DEPARTMENT_LIST, 'GET', $args);

			return Department::Array2DepartmentList($this->repJson);
		}

		/* 标签管理 */
		public function tagCreate (Tag $tag, &$tagId)
		{
			Tag::CheckTagCreateArgs($tag);
			$args = Tag::Tag2Array($tag);
			self::_HttpCall(self::TAG_CREATE, 'POST', $args);

			$tagId = Utils::arrayGet($this->repJson, 'tagid');
		}

		public function tagUpdate (Tag $tag)
		{
			Tag::CheckTagUpdateArgs($tag);
			$args = Tag::Tag2Array($tag);
			self::_HttpCall(self::TAG_UPDATE, 'POST', $args);

			return $this->repJson;
		}

		public function tagDelete ($tagId)
		{
			Utils::checkIsUInt($tagId, 'tagid');
			self::_HttpCall(self::TAG_DELETE, 'GET', ['tagid' => $tagId]);

			return $this->repJson;
		}

		public function tagGet ($tagId)
		{
			Utils::checkIsUInt($tagId, 'tagid');
			self::_HttpCall(self::TAG_GET, 'GET', ['tagid' => $tagId]);

			return Tag::parseFromArray($this->repJson);
		}

		public function tagAddTagUsers ($tagId, $userIdList = [], $partyIdList = [])
		{
			Tag::CheckTagADUserArgs($tagId, $userIdList, $partyIdList);

			$args = Tag::ToTagADUserArray($tagId, $userIdList, $partyIdList);

			self::_HttpCall(self::TAG_ADD_TAG_USERS, 'POST', $args);

			return $this->repJson;
		}

		public function tagDelTagUsers ($tagId, $userIdList = [], $partyIdList = [])
		{
			Tag::CheckTagADUserArgs($tagId, $userIdList, $partyIdList);

			$args = Tag::ToTagADUserArray($tagId, $userIdList, $partyIdList);

			self::_HttpCall(self::TAG_DEL_TAG_USERS, 'POST', $args);

			return $this->repJson;
		}

		public function tagList ()
		{
			self::_HttpCall(self::TAG_LIST);

			return Tag::Array2TagList($this->repJson);
		}

		/* 异步批量接口 */
		private function batchJob (BatchJobArgs $batchJobArgs, $jobType)
		{
			Batch::CheckBatchJobArgs($batchJobArgs);
			$args = Utils::Object2Array($batchJobArgs);
			$url  = '';
			switch ($jobType) {
				case 'syncsuser':
					$url = self::BATCH_SYNC_USER;

					break;
				case 'replaceuser':
					$url = self::BATCH_REPLACE_USER;

					break;
				case 'replaceparty':
					$url = self::BATCH_REPLACE_PARTY;

					break;
				default:
					break;
			}

			if (!Utils::notEmptyStr($url)) {
				throw new \QyApiError('job type not invlide.');
			}

			self::_HttpCall($url, 'POST');

			return Utils::arrayGet($this->repJson, 'jobid');
		}

		public function batchSyncUser (BatchJobArgs $batchJobArgs)
		{
			return self::batchJob($batchJobArgs, 'syncuser');
		}

		public function batchReplaceUser (BatchJobArgs $batchJobArgs)
		{
			return self::batchJob($batchJobArgs, 'replaceuser');
		}

		public function batchReplaceParty (BatchJobArgs $batchJobArgs)
		{
			return self::batchJob($batchJobArgs, 'replaceparty');
		}

		public function batchGetResult ($jobId)
		{
			Utils::checkNotEmptyStr($jobId, 'jobid');
			self::_HttpCall(self::BATCH_GET_RESULT, 'GET', ['jobid' => $jobId]);

			return Batch::parseFromArray($this->repJson);
		}

		/* 企业服务人员管理 */
		public function ECGetFollowUserList ()
		{
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_FOLLOW_USER_LIST);

			return $this->repJson;
		}

		public function ECAddContactWay (ExternalContactWay $externalContactWay)
		{
			ExternalContactWay::CheckExternalContactWayAddArgs($externalContactWay);
			$args = Utils::Object2EmptyArray($externalContactWay);
			self::_HttpCall(self::EXTERNAL_CONTACT_ADD_CONTACT_WAY, 'POST', $args);

			return $this->repJson;
		}

		public function ECGetContactWay ($configId)
		{
			Utils::checkNotEmptyStr($configId, 'config_id');
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_CONTACT_WAY, 'POST', ['config_id' => $configId]);

			return ExternalContact::wayParseFromArray($this->repJson);
		}

		public function ECGetUserInfo ($userId)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::USER_GET, 'GET', ['userid' => $userId]);

			return $this->repJson;
		}

		public function ECUpdateContactWay (ExternalContactWay $externalContactWay)
		{
			ExternalContactWay::CheckExternalContactWayUpdateArgs($externalContactWay);
			$args = Utils::Object2EmptyArray($externalContactWay);
			self::_HttpCall(self::EXTERNAL_CONTACT_UPDATE_CONTACT_WAY, 'POST', $args);

			return $this->repJson;
		}

		public function ECDelContactWay ($configId)
		{
			Utils::checkNotEmptyStr($configId, 'config_id');
			self::_HttpCall(self::EXTERNAL_CONTACT_DEL_CONTACT_WAY, 'POST', ['config_id' => $configId]);

			return $this->repJson;
		}

		/* 客户管理 */
		public function ECList ($userId)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::EXTERNAL_CONTACT_LIST, 'GET', ['userid' => $userId]);

			return $this->repJson;
		}

		public function ECGet ($externalUserId)
		{
			Utils::checkNotEmptyStr($externalUserId, 'external userid');
			self::_HttpCall(self::EXTERNAL_CONTACT_GET, 'GET', ['external_userid' => $externalUserId]);

			$externalContact                = Utils::arrayGet($this->repJson, 'external_contact');
			$externalContact['follow_user'] = Utils::arrayGet($this->repJson, 'follow_user');

			return ExternalContact::parseFromArray($externalContact);
		}

		public function ECBatchGetByUser (ExternalContactBatchGetByUser $batchGetByUser)
		{
			ExternalContactBatchGetByUser::CheckExternalContactBatchGetByUserArgs($batchGetByUser);
			$args = Utils::Object2EmptyArray($batchGetByUser);
			self::_HttpCall(self::EXTERNAL_CONTACT_BATCH_GET_BY_USER, 'POST', $args);

			$externalContactListInfo = [
				'external_contact_list' => Utils::arrayGet($this->repJson, 'external_contact_list'),
				'next_cursor'           => Utils::arrayGet($this->repJson, 'next_cursor')
			];

			return $externalContactListInfo;
		}

		public function ECRemark (ExternalContactRemark $externalContactRemark)
		{
			ExternalContactRemark::CheckExternalContactRmarkArgs($externalContactRemark);
			$args = Utils::Object2EmptyArray($externalContactRemark);
			self::_HttpCall(self::EXTERNAL_CONTACT_REMARK, 'POST', $args);

			return $this->repJson;
		}

		/* 客户标签管理 */
		public function ECGetCorpTagList ($tagIdList = NULL, $groupIdList = NULL)
		{
			$args = [];
			if (!is_null($tagIdList)) {
				Utils::checkNotEmptyArray($tagIdList, 'tag id list');
				$args['tag_id'] = $tagIdList;
			}
			if (!is_null($groupIdList)) {
				Utils::checkNotEmptyArray($groupIdList, 'group id list');
				$args['group_id'] = $groupIdList;
			}

			self::_HttpCall(self::EXTERNAL_CONTACT_GET_CORP_TAG_LIST, 'POST', $args);

			return ExternalContactTagGroup::arrayToTagGroup($this->repJson);
		}

		public function ECAddCorpTag (ExternalContactTagGroup $tagGroup)
		{
			ExternalContactTagGroup::checkExternalContactTagGroupAddArgs($tagGroup);
			$args = Utils::Object2Array($tagGroup);
			self::_HttpCall(self::EXTERNAL_CONTACT_ADD_CORP_TAG, 'POST', $args);

			return ExternalContactTagGroup::parseFromArray(Utils::arrayGet($this->repJson, 'tag_group'));
		}

		public function ECEditCorpTag (ExternalContactTag $tag)
		{
			ExternalContactTag::checkExternalContactTagEditArgs($tag);
			$args = Utils::Object2Array($tag);
			self::_HttpCall(self::EXTERNAL_CONTACT_EDIT_CORP_TAG, 'POST', $args);

			return $this->repJson;
		}

		public function ECDelCorpTag ($tagIdList = [], $groupIdList = [])
		{
			if (!Utils::notEmptyArray($tagIdList) && !Utils::notEmptyArray($groupIdList)) {
				throw new \QyApiError('input error paramter.');
			}

			$args = [];
			if (Utils::notEmptyArray($tagIdList)) {
				$args['tag_id'] = $tagIdList;
			}
			if (Utils::notEmptyArray($groupIdList)) {
				$args['group_id'] = $groupIdList;
			}

			self::_HttpCall(self::EXTERNAL_CONTACT_DEL_CORP_TAG, 'POST', $args);

			return $this->repJson;
		}

		public function ECMarkTag ($userId, $externalUserId, $addTagList = [], $removeTagList = [])
		{
			Utils::checkNotEmptyStr($userId, 'user id');
			Utils::checkNotEmptyStr($externalUserId, 'external user id');

			if (!Utils::notEmptyArray($addTagList) && !Utils::notEmptyArray($removeTagList)) {
				throw new \QyApiError('input error paramter.');
			}

			$args = [
				'userid'          => $userId,
				'external_userid' => $externalUserId
			];
			if (Utils::notEmptyArray($addTagList)) {
				$args['add_tag'] = $addTagList;
			}
			if (Utils::notEmptyArray($removeTagList)) {
				$args['remove_tag'] = $removeTagList;
			}

			self::_HttpCall(self::EXTERNAL_CONTACT_MARK_TAG, 'POST', $args);

			return $this->repJson;
		}

		//获取规则组列表
		public function ECStrategyList ($limit = 1000, $cursor = '')
		{
			$args = [
				'cursor' => $cursor,
				'limit'  => $limit,
			];
			self::_HttpCall(self::EXTERNAL_CONTACT_STRATEGY_LIST, 'POST', $args);

			return $this->repJson;
		}

		//获取规则组详情
		public function ECStrategyGet ($strategyId)
		{
			$args = [
				'strategy_id' => $strategyId
			];
			self::_HttpCall(self::EXTERNAL_CONTACT_STRATEGY_GET, 'POST', $args);

			return $this->repJson;
		}

		//获取规则组管理范围
		public function ECStrategyGetRange ($strategyId, $limit = 1000, $cursor = '')
		{
			$args = [
				'strategy_id' => $strategyId,
				'cursor'      => $cursor,
				'limit'       => $limit,
			];
			self::_HttpCall(self::EXTERNAL_CONTACT_STRATEGY_GET_RANGE, 'POST', $args);

			return $this->repJson;
		}

		//创建新的规则组
		public function ECStrategyCreate ($args)
		{
			self::_HttpCall(self::EXTERNAL_CONTACT_STRATEGY_CREATE, 'POST', $args);

			return $this->repJson;
		}

		//编辑规则组及其管理范围
		public function ECStrategyEdit ($args)
		{
			self::_HttpCall(self::EXTERNAL_CONTACT_STRATEGY_EDIT, 'POST', $args);

			return $this->repJson;
		}

		//删除规则组
		public function ECStrategyDel ($strategyId)
		{
			$args = [
				'strategy_id' => $strategyId
			];
			self::_HttpCall(self::EXTERNAL_CONTACT_STRATEGY_DEL, 'POST', $args);

			return $this->repJson;
		}

		// TODO: 还需要优化
		public function ECGroupChatList ($offset = 0, $limit = 100, $statusFilter = 0, $ownerFilter = [])
		{
			$args = [
				'status_filter' => $statusFilter,
				'offset'        => $offset,
				'limit'         => $limit,
			];
			if (Utils::notEmptyArray($ownerFilter)) {
				$args['owner_filter'] = $ownerFilter;
			}

			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_CHAT_LIST, 'POST', $args);

			return $this->repJson;
		}

		public function ECGroupChatStaticTime ($s_time, $e_time, $ownerIds)
		{
			$args = [
				'day_begin_time' => $s_time,
				'day_end_time'   => $e_time,
				'owner_filter'   => [
					"userid_list" => $ownerIds
				],
			];
			Utils::checkNotEmptyArray($ownerIds, 'userid_list');

			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_CHAT_STATIC_GET, 'POST', $args);

			return $this->repJson;
		}

		public function ECGroupChatGet ($chatId, $needName = 0)
		{
			Utils::checkNotEmptyStr($chatId, 'chat_id');
			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_CHAT_GET, 'POST', ['chat_id' => $chatId, 'need_name' => $needName]);

			return ExternalContactGroupChat::parseFromArray(Utils::arrayGet($this->repJson, 'group_chat'));
		}

		public function ECAddMsgTemplate (ExternalContactMsgTemplate $msgTemplate)
		{
			ExternalContactMsgTemplate::checkMsgTemplateAddArgs($msgTemplate);
			$args = Utils::Object2Array($msgTemplate);
			self::_HttpCall(self::EXTERNAL_CONTACT_ADD_MSG_TEMPLATE, "POST", $args);

			return $this->repJson;
		}

		public function ECGetGroupMsgResult ($msgId)
		{
			Utils::checkNotEmptyStr($msgId, 'msgid');
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_GROUP_MSG_RESULT, 'POST', ['msgid' => $msgId]);

			return $this->repJson;
		}

		public function ECGetGroupMsgTask ($sendData)
		{
			Utils::checkNotEmptyStr($sendData['msgid'] ?? '', 'msgid');
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_GROUP_MSG_TASK, 'POST', $sendData);

			return $this->repJson;
		}

		public function ECGetGroupmsgSendResult ($sendData)
		{
			Utils::checkNotEmptyStr($sendData['msgid'] ?? '', 'msgid');
			Utils::checkNotEmptyStr($sendData['userid'] ?? '', 'userid');
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_GROUPMSG_SEND_RESULT, 'POST', $sendData);

			return $this->repJson;
		}

		public function ECRemindGroupmsgSend ($sendData)
		{
			Utils::checkNotEmptyStr($sendData['msgid'] ?? '', 'msgid');
			self::_HttpCall(self::EXTERNAL_CONTACT_REMIND_GROUPMSG_SEND, 'POST', $sendData);

			return $this->repJson;
		}

		public function ECCancelGroupmsgSend ($sendData)
		{
			Utils::checkNotEmptyStr($sendData['msgid'] ?? '', 'msgid');
			self::_HttpCall(self::EXTERNAL_CONTACT_CANCEL_GROUPMSG_SEND, 'POST', $sendData);

			return $this->repJson;
		}

		public function ECGetGroupmsgListV2 ($sendData, $limit = 100, $cursor = '')
		{
			Utils::checkNotEmptyStr($sendData['chat_type'] ?? '', 'chat_type');
			Utils::checkNotEmptyStr($sendData['creator'] ?? '', 'creator');

			$sendData['limit'] = $limit;
			if (!empty($cursor)) {
				$sendData['cursor'] = $cursor;
			}

			self::_HttpCall(self::EXTERNAL_CONTACT_GET_GROUPMSG_LIST_V2, 'POST', $sendData);

			return $this->repJson;
		}

		public function ECSendWelcomeMsg (ExternalContactMsgTemplate $msgTemplate)
		{
			ExternalContactMsgTemplate::checkMsgTemplateSendArgs($msgTemplate);
			$args = Utils::Object2Array($msgTemplate);
			self::_HttpCall(self::EXTERNAL_CONTACT_SEND_WELCOME_MSG, "POST", $args);

			return $this->repJson;
		}

		public function ECGroupWelcomeTemplateAdd (ExternalContactMsgTemplate $msgTemplate)
		{
			ExternalContactMsgTemplate::checkGroupWelcomeTemplateAddArgs($msgTemplate);
			if (isset($msgTemplate->notify)) {
				$notify = $msgTemplate->notify;
			}
			$args = Utils::Object2Array($msgTemplate);
			if (isset($notify)) {
				$args['notify'] = $notify;
			}
			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_WELCOME_TEMPLATE_ADD, "POST", $args);

			return $this->repJson;
		}

		public function ECGroupWelcomeTemplateEdit (ExternalContactMsgTemplate $msgTemplate)
		{
			ExternalContactMsgTemplate::checkGroupWelcomeTemplateEditArgs($msgTemplate);
			$args = Utils::Object2Array($msgTemplate);
			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_WELCOME_TEMPLATE_EDIT, "POST", $args);

			return $this->repJson;
		}

		public function ECGroupWelcomeTemplateGet ($templateId)
		{
			Utils::checkNotEmptyStr($templateId, 'template_id');
			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_WELCOME_TEMPLATE_GET, "POST", ['template_id' => $templateId]);

			return ExternalContactMsgTemplate::parseFromArray($this->repJson);
		}

		public function ECGroupWelcomeTemplateDel ($templateId)
		{
			Utils::checkNotEmptyStr($templateId, 'template_id');
			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_WELCOME_TEMPLATE_DEL, "POST", ['template_id' => $templateId]);

			return $this->repJson;
		}

		public function ECGetUnAssignedList ($pageId = 0, $pageSize = 1000)
		{
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_UNASSIGNED_LIST, 'POST', ['page_id' => $pageId, 'page_size' => $pageSize]);

			return ExternalContactUnAssignUser::arrayToUnAssignUserInfo($this->repJson);
		}

		public function ECGetUnAssignedListPage ($pageId = 0, $pageSize = 1000, $cursor = '')
		{
			$params['page_size'] = $pageSize;
			if (!empty($cursor)) {
				mb_strlen($cursor) > 1 && $params['cursor'] = $cursor;
			} else {
				$params['page_id'] = $pageId;
			}
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_UNASSIGNED_LIST, 'POST', $params);

			return $this->repJson;
		}

		public function ECCalendarAdd ($params)
		{
			self::_HttpCall(self::CALENDAR_ADD, 'POST', $params);

			return $this->repJson;
		}

		public function ECCalendarGet ($params)
		{
			self::_HttpCall(self::CALENDAR_GET, 'POST', $params);

			return $this->repJson;
		}

		public function ECScheduleAdd ($params)
		{
			self::_HttpCall(self::OA_SCHEDULE_ADD, 'POST', $params);

			return $this->repJson;
		}

		public function ECScheduleGet ($params)
		{
			self::_HttpCall(self::OA_SCHEDULE_GET, 'POST', $params);

			return $this->repJson;
		}

		public function ECScheduleUpdate ($params)
		{
			self::_HttpCall(self::OA_SCHEDULE_UPDATE, 'POST', $params);

			return $this->repJson;
		}

		public function ECAddJoinWay ($params)
		{
			self::_HttpCall(self::ADD_JOIN_WAY, 'POST', $params);

			return $this->repJson;
		}

		public function ECGetJoinWay ($params)
		{
			self::_HttpCall(self::GET_JOIN_WAY, 'POST', $params);

			return $this->repJson;
		}

		public function ECUpdateJoinWay ($params)
		{
			self::_HttpCall(self::UPDATE_JOIN_WAY, 'POST', $params);

			return $this->repJson;
		}

		public function getSpModuleDetail ($t_id)
		{
			$args = [
				'template_id' => $t_id,
			];

			self::_HttpCall(self::SERVICE_GET_SP_MODULE_DETAIL, 'POST', $args);

			return $this->repJson;
		}

		public function getApprovalList ($data)
		{
			$args = [
				'starttime' => $data['startTime'],
				'endtime'   => $data['endTime'],
				'cursor'    => $data['cursor'],
				'size'      => $data['size'],
			];

			self::_HttpCall(self::OA_GET_APPROVAL_INFO, 'POST', $args);

			return $this->repJson;
		}

		public function addSpModule ($data)
		{
			self::_HttpCall(self::OA_APPROVAL_CREATE_TEMPLATE, 'POST', $data);

			return $this->repJson;
		}

		public function getSysTemplate ($data)
		{
			self::_HttpCall(self::OA_GET_TEMPLATE_DETIAL, 'POST', $data);

			return $this->repJson;
		}

		public function getTemplateInfo ($data)
		{
			$args = [
				'template_id' => $data,
			];
			self::_HttpCall(self::OA_GET_TEMPLATE_DETIAL, 'POST', $args);

			return $this->repJson;
		}

		public function addSubmitSp ($data)
		{
			self::_HttpCall(self::OA_APPLY_EVENT, 'POST', $data);

			return $this->repJson;
		}

		public function getApprovalDetail ($data)
		{
			$args = [
				'sp_no' => $data,
			];

			self::_HttpCall(self::OA_GET_APPROVAL_DETAIL, 'POST', $args);

			return $this->repJson;
		}

		public function ECTransfer ($externalUserId, $handoverUserId, $takeoverUserId)
		{
			Utils::checkNotEmptyStr($externalUserId, 'external_userid');
			Utils::checkNotEmptyStr($handoverUserId, 'handover_userid');
			Utils::checkNotEmptyStr($takeoverUserId, 'takeover_userid');

			$args = [
				'external_userid' => $externalUserId,
				'handover_userid' => $handoverUserId,
				'takeover_userid' => $takeoverUserId,
			];
			self::_HttpCall(self::EXTERNAL_CONTACT_TRANSFER, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 分配离职成员的客户
		 *
		 * @param       $handoverUserId
		 * @param       $takeoverUserId
		 * @param array $externalUserId
		 *
		 * @return |null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function ECTransferCustomer ($handoverUserId, $takeoverUserId, array $externalUserId)
		{
			Utils::checkNotEmptyStr($handoverUserId, 'handover_userid');
			Utils::checkNotEmptyStr($takeoverUserId, 'takeover_userid');
			Utils::notEmptyArray($externalUserId, 'external_userid');

			$args = [
				'handover_userid' => $handoverUserId,
				'takeover_userid' => $takeoverUserId,
				'external_userid' => $externalUserId,
			];
			self::_HttpCall(self::EXTERNAL_CONTACT_TRANSFER_CUSTOMER, 'POST', $args);

			return $this->repJson;
		}

		public function ECGroupChatTransfer ($chadIdList, $newOwner)
		{
			Utils::checkNotEmptyArray($chadIdList, 'chat_id_list');
			Utils::checkNotEmptyStr($newOwner, 'new_owner');

			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_CHAT_TRANSFER, 'POST', ['chat_id_list' => $chadIdList, 'new_owner' => $newOwner]);

			return $this->repJson;
		}

		public function EContactGetTransferResult ($handoverData)
		{
			$args = Utils::Object2Array($handoverData);
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_TRANSFER_RESULT, 'POST', $args);

			return $this->repJson;
		}

		public function EContactResignedTransferResult ($handoverData)
		{
			$args = Utils::Object2Array($handoverData);
			self::_HttpCall(self::EXTERNAL_CONTACT_TRANSFER_RESULT, 'POST', $args);

			return $this->repJson;
		}

		public function EContactTransferResult ($handoverData)
		{
			$args = Utils::Object2Array($handoverData);
			self::_HttpCall(self::EXTERNALCONTACT_TRANSFER_RESULT, 'POST', $args);

			return $this->repJson;
		}

		public function ECGetUserBeheviorData ($behavior)
		{
			ExternalContactBehavior::checkBehaviorGetArgs($behavior);
			self::_HttpCall(self::EXTERNAL_CONTACT_GET_USER_BEHAVIOR_DATA, 'POST', $behavior);

			return ExternalContactBehavior::arrayToBehaviorData($this->repJson);
		}

		public function ECGroupChatStatistic ($filter)
		{
			ExternalContactGroupChat::checkGroupChatStatisticArgs($filter);
			self::_HttpCall(self::EXTERNAL_CONTACT_GROUP_CHAT_STATISTIC, 'POST', $filter);

			return $this->repJson;
		}

		public function GetUserInfoByCode ($code)
		{
			Utils::checkNotEmptyStr($code, "code");
			self::_HttpCall(self::USR_GET_USER_INFO, 'GET', ['code' => $code]);

			return UserInfoByCode::parseFromArray($this->repJson);
		}

		public function GetUserDetail ($userTicket)
		{
			Utils::checkNotEmptyStr($userTicket, "user_ticket");
			self::_HttpCall(self::USER_GET_USER_DETAIL, 'POST', ['user_ticket' => $userTicket]);

			return UserDetailByUserTicket::parseFromArray($this->repJson);
		}

		/* 素材管理 */
		public function MediaUpload ($filePath, $type, $plus = [])
		{
			Utils::checkNotEmptyStr($filePath, "filePath");
			Utils::checkNotEmptyStr($type, "type");

			if (!file_exists($filePath)) {
				throw new \QyApiError("file not exists");
			}
			$fileName = !empty($plus['file_name']) ? $plus['file_name'] : basename($filePath);
			// 兼容php5.3-5.6 curl模块的上传操作
			$args = [];
			if (class_exists('\CURLFile')) {
				$args = ['media' => new \CURLFile(realpath($filePath), 'application/octet-stream', $fileName)];
			} else {
				$args = ['media' => '@' . realpath($filePath)];
			}

			self::_HttpCall(self::MEDIA_UPLOAD . '&type=' . $type, 'POST', $args, true, true);

			return $this->repJson["media_id"];
		}

		/*异步上传临时素材*/
		public function MediaUploadByUrl ($filePath, $filename, $url, $scene = 0, $type = 'file')
		{
			Utils::checkNotEmptyStr($filePath, "filePath");
			Utils::checkNotEmptyStr($filename, "filename");
			Utils::checkNotEmptyStr($url, "url");

			if (file_exists($filePath)) {
				$md5 = md5_file($filePath);
			} else {
				throw new \QyApiError("file not exists");
			}
			$args = [
				'scene'    => $scene,
				'type'     => $type,
				'filename' => $filename,
				'url'      => $url,
				'md5'      => $md5,
			];
			var_dump($args);
			self::_HttpCall(self::MEDIA_UPLOAD_BY_URL, 'POST', $args, true);

			return $this->repJson["jobid"];
		}

		/*获取异步上传素材文件结果*/
		public function MediaGetUploadByUrlResult ($jobid)
		{
			Utils::checkNotEmptyStr($jobid, "jobid");
			$args = [
				'jobid' => $jobid
			];
			self::_HttpCall(self::MEDIA_GET_UPLOAD_BY_URL_RESULT, 'POST', $args, true);

			return $this->repJson;
		}

		/* 附件管理 */
		public function Enclosure ($filePath, $type, $plus = [])
		{
			Utils::checkNotEmptyStr($filePath, "filePath");
			Utils::checkNotEmptyStr($type, "type");
			if (!file_exists($filePath)) {
				throw new \QyApiError("file not exists");
			}
			$fileName = !empty($plus['file_name']) ? $plus['file_name'] : basename($filePath);
			// 兼容php5.3-5.6 curl模块的上传操作
			$args = [];
			if (class_exists('\CURLFile')) {
				$args = ['media' => new \CURLFile(realpath($filePath), 'application/octet-stream', $fileName)];
			} else {
				$args = ['media' => '@' . realpath($filePath)];
			}

			//self::_HttpCall(self::MEDIA_UPLOAD . '&type=' . $type, 'POST', $args, true, true);
			self::_HttpCall(self::MESSAGE_SEND_UPLOAD_ATTACMENT . '&media_type=' . $type . '&attachment_type=' . 1, 'POST', $args, true, true);

			return $this->repJson["media_id"];
		}

		public function MediaUploadByBuffer ($buffer, $type)
		{
			$tmpPath = self::WriteTmpFile($buffer);

			try {
				$ret = $this->mediaUpload($tmpPath, $type);
				unlink($tmpPath);

				return $ret;
			} catch (Exception $ex) {
				unlink($tmpPath);
				throw $ex;
			}
		}

		public function MediaGet ($media_id)
		{
			Utils::checkNotEmptyStr($media_id, "media_id");
			self::_HttpCall(self::MEDIA_GET, 'GET', ['media_id' => $media_id]);

			return $this->repRawStr;
		}

		public function UploadImage ($filePath, $md5 = NULL)
		{
			Utils::checkNotEmptyStr($filePath, "filePath");
			if (!file_exists($filePath)) {
				throw new \QyApiError("file not exists");
			}

			// 兼容php5.3-5.6 curl模块的上传操作
			$args = [];
			if (class_exists('\CURLFile')) {
				$args = ['media' => new \CURLFile(realpath($filePath), 'application/octet-stream', basename($filePath))];
			} else {
				$args = ['media' => '@' . $filePath];//realpath($filePath));
			}

			self::_HttpCall(self::MEDIA_UPLOAD_IMG, 'POST', $args, true, true);

			return $this->repJson["url"];
		}

		/* 发送应用消息 */
		public function MessageSend (Message $message, &$invalidUserIdList, &$invalidPartyIdList, &$invalidTagIdList)
		{
			Message::CheckMessageSendArgs($message);
			$args = Message::Message2Array($message);

			self::_HttpCall(self::MESSAGE_SEND, 'POST', $args);

			$this->getInvalidList($invalidUserIdList, $invalidPartyIdList, $invalidTagIdList);

			return $this->repJson;
		}

		/* 朋友圈群發 */
		/**
		 * @throws \QyApiError
		 * @throws \HttpError
		 * @throws \NetWorkError
		 */
		public function MessageSendMomentTask ($message)
		{
			self::_HttpCall(self::MESSAGE_SEND_MOMENT_TASK, 'POST', $message);

			return $this->repJson;
		}

		/**
		 * 朋友圈群发撤销
		 * @throws \QyApiError
		 * @throws \HttpError
		 * @throws \NetWorkError
		 */
		public function cancelMomentTask ($message)
		{
			self::_HttpCall(self::CANCEL_MOMENT_TASK, 'POST', $message);

			return $this->repJson;
		}

		/*
		 * 上传附件1
		 * */
		public function MessageSendUploadMoment ($type, $media)
		{
			self::_HttpCall(self::MESSAGE_SEND_UPLOAD_ATTACMENT . '&type=' . $type . '&attachment_type=' . 1, 'POST', $media);

			return $this->repJson;
		}

		/* 互联企业消息推送 */
		public function LinkedMessageSend (LinkedcorpMessage $message, &$invalidUserIdList, &$invalidPartyIdList, &$invalidTagIdList)
		{
			LinkedcorpMessage::CheckMessageSendArgs($message);
			$args = LinkedcorpMessage::Message2Array($message);

			self::_HttpCall(self::LINKED_CORP_MESSAGE_SEND, 'POST', $args);

			$this->getInvalidList($invalidUserIdList, $invalidPartyIdList, $invalidTagIdList);
		}

		public function GetJsapiTicket ()
		{
			self::_HttpCall(self::GET_JSAPI_TICKET);

			return $this->repJson;
		}

		public function TicketGet ()
		{
			self::_HttpCall(self::TICKET_GET, 'GET', ['type' => 'agent_config']);

			return $this->repJson;
		}

		public function JsApiSignatureGet ($jsapiTicket, $nonceStr, $timestamp, $url)
		{
			$string = "jsapi_ticket=$jsapiTicket&noncestr=$nonceStr&timestamp=$timestamp&url=$url";

			return sha1($string);
		}

		public function AgentGet ($agentId)
		{
			self::_HttpCall(self::AGENT_GET, 'GET', ['agentid' => $agentId]);

			return $this->repJson;
		}

		public function AgentSet (Agent $agent)
		{
			Agent::CheckAgentSetArgs($agent);
			$args = Agent::AgentSetArgs($agent);
			self::_HttpCall(self::AGENT_SET, 'POST', $args);

			return $this->repJson;
		}

		public function AgentList ()
		{
			self::_HttpCall(self::AGENT_LIST);

			return $this->repJson;
		}

		public function GetPermitUserList ()
		{
			self::_HttpCall(self::GET_PERMIT_USER_LIST);

			return $this->repJson;
		}

		public function CheckSingleAgree (MsgAuditCheckAgree $msgAuditCheck)
		{
			MsgAuditCheckAgree::CheckSingleAgreeArgs($msgAuditCheck);
			$args = MsgAuditCheckAgree::SetSingleAgreeArgs($msgAuditCheck);
			self::_HttpCall(self::CHECK_SINGLE_AGREE, 'POST', $args);

			return $this->repJson;
		}

		public function CheckRoomAgree (MsgAuditCheckAgree $msgAuditCheck)
		{
			MsgAuditCheckAgree::CheckRoomAgreeArgs($msgAuditCheck);
			$args = MsgAuditCheckAgree::SetRoomAgreeArgs($msgAuditCheck);
			self::_HttpCall(self::CHECK_ROOM_AGREE, 'POST', $args);

			return $this->repJson;
		}

		public function GroupChatGet ($roomId)
		{

			Utils::checkNotEmptyStr($roomId, 'roomid');
			self::_HttpCall(self::GROUP_CHAT_GET, 'POST', ['roomid' => $roomId]);

			return $this->repJson;
		}

		public function GetMomentList ($startTime, $endTime, $creator = '', $filterType = 2, $cursor = '', $limit = 100)
		{
			Utils::checkNotEmptyStr($startTime, 'start_time');
			Utils::checkNotEmptyStr($endTime, 'end_time');
			if ($limit < 1 || $limit > 100) {
				throw new \ParameterError('limit must be inter and lt 100');
			}
			$args = [
				'start_time'  => $startTime,
				'end_time'    => $endTime,
				'filter_type' => $filterType,
				'cursor'      => $cursor,
				'limit'       => $limit,
			];

			if (!empty($creator)) {
				$args['creator'] = $creator;
			}

			self::_HttpCall(self::GET_MOMENT_LIST, 'POST', $args);

			return $this->repJson;
		}

		public function GetMomentTask ($momentId, $cursor = '', $limit = 500)
		{
			Utils::checkNotEmptyStr($momentId, 'moment_id');
			if ($limit < 1 || $limit > 1000) {
				throw new \ParameterError('limit must be inter and lt 1000');
			}
			$args = [
				'moment_id' => $momentId,
				'cursor'    => $cursor,
				'limit'     => $limit,
			];
			self::_HttpCall(self::GET_MOMENT_TASK, 'POST', $args);

			return $this->repJson;
		}

		public function GetMomentCustomerList ($momentId, $userId, $cursor = '', $limit = 500)
		{
			Utils::checkNotEmptyStr($momentId, 'moment_id');
			Utils::checkNotEmptyStr($userId, 'user_id');
			if ($limit < 1 || $limit > 1000) {
				throw new \ParameterError('limit must be inter and lt 1000');
			}
			$args = [
				'moment_id' => $momentId,
				'userid'    => $userId,
				'cursor'    => $cursor,
				'limit'     => $limit,
			];
			self::_HttpCall(self::GET_MOMENT_CUSTOMER_LIST, 'POST', $args);

			return $this->repJson;
		}

		public function GetMomentSendResult ($momentId, $userId, $cursor = '', $limit = 3000)
		{
			Utils::checkNotEmptyStr($momentId, 'moment_id');
			Utils::checkNotEmptyStr($userId, 'user_id');
			if ($limit < 1 || $limit > 5000) {
				throw new \ParameterError('limit must be inter and lt 5000');
			}
			$args = [
				'moment_id' => $momentId,
				'userid'    => $userId,
				'cursor'    => $cursor,
				'limit'     => $limit,
			];
			self::_HttpCall(self::GET_MOMENT_SEND_RESULT, 'POST', $args);

			return $this->repJson;
		}

		public function GetMomentComments ($momentId, $userId)
		{
			Utils::checkNotEmptyStr($momentId, 'moment_id');
			Utils::checkNotEmptyStr($userId, 'user_id');
			$args = [
				'moment_id' => $momentId,
				'userid'    => $userId,
			];
			self::_HttpCall(self::GET_MOMENT_COMMENTS, 'POST', $args);

			return $this->repJson;
		}

		/* 微信客服 */
		/**
		 * 添加客服帐号
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfAccountAdd
		 *
		 * @param string $name     // 客服帐号名称。不多于16个字符
		 * @param string $media_id // 客服头像临时素材。可以调用上传临时素材接口获取。不多于128个字节
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfAccountAdd (string $name, string $media_id)
		{
			Utils::checkNotEmptyStr($name, 'name');
			Utils::checkNotEmptyStr($media_id, 'media_id');
			$args = [
				'name'     => $name,
				'media_id' => $media_id
			];

			self::_HttpCall(self::WECHAT_KF_ACCOUNT_ADD, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 删除客服帐号
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfAccountDel
		 *
		 * @param string $open_kfid //客服帐号ID。不多于64字节
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfAccountDel (string $open_kfid)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			$args = [
				'open_kfid' => $open_kfid
			];

			self::_HttpCall(self::WECHAT_KF_ACCOUNT_DEL, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 修改客服账号
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfAccountUpdate
		 *
		 * @param string $open_kfid //客服帐号ID。不多于64字节
		 * @param string $name      // 客服帐号名称。不多于16个字符
		 * @param string $media_id  // 客服头像临时素材。可以调用上传临时素材接口获取。不多于128个字节
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfAccountUpdate (string $open_kfid, string $name = '', string $media_id = '')
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			$args = [
				'open_kfid' => $open_kfid
			];

			if (!empty($name)) {
				$args['name'] = $name;
			}

			if (!empty($media_id)) {
				$args['media_id'] = $media_id;
			}

			self::_HttpCall(self::WECHAT_KF_ACCOUNT_UPDATE, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 获取客服账号列表
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfAccountList
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \QyApiError
		 */
		public function kfAccountList ()
		{
			self::_HttpCall(self::WECHAT_KF_ACCOUNT_LIST, 'GET');

			return $this->repJson;
		}

		/**
		 * 获取客服帐号链接
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfAddContactWay
		 *
		 * @param string $open_kfid //客服帐号ID。不多于64字节
		 * @param string $scene     //场景值，字符串类型，由开发者自定义。不多于32字节 字符串取值范围(正则表达式)：[0-9a-zA-Z_-]*
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfAddContactWay (string $open_kfid, string $scene = '')
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			$args = [
				'open_kfid' => $open_kfid
			];

			if (!empty($scene)) {
				$args['scene'] = $scene;
			}

			self::_HttpCall(self::WECHAT_KF_ADD_CONTACT_WAY, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 添加接待人员
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfServicerAdd
		 *
		 * @param string $open_kfid   //客服帐号ID
		 * @param array  $userid_list //接待人员userid列表。第三方应用填密文userid，即open_userid
		 *                            可填充个数：1 ~ 100。超过100个需分批调用。
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfServicerAdd (string $open_kfid, array $userid_list)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyArray($userid_list, 'userid_list');

			if (count($userid_list) > 100) {
				$num          = ceil(count($userid_list) / 100);
				$chunk_result = array_chunk($userid_list, $num);
				$result       = [];
				$result_list  = [];
				foreach ($chunk_result as $list) {
					$args = [
						'open_kfid'   => $open_kfid,
						'userid_list' => $list
					];

					self::_HttpCall(self::WECHAT_KF_SERVICER_ADD, 'POST', $args);
					$result_list[] = $this->repJson;
				}
				if (!empty($result_list)) {
					$result = array_merge($result, ...$result_list);
				}

			} else {
				$args = [
					'open_kfid'   => $open_kfid,
					'userid_list' => $userid_list
				];

				self::_HttpCall(self::WECHAT_KF_SERVICER_ADD, 'POST', $args);
				$result = $this->repJson;
			}

			return $result;
		}

		/**
		 * 删除接待人员
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfServicerDel
		 *
		 * @param string $open_kfid   //客服帐号ID
		 * @param array  $userid_list //接待人员userid列表。第三方应用填密文userid，即open_userid
		 *                            可填充个数：1 ~ 100。超过100个需分批调用。
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfServicerDel (string $open_kfid, array $userid_list)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyArray($userid_list, 'userid_list');

			if (count($userid_list) > 100) {
				$num          = ceil(count($userid_list) / 100);
				$chunk_result = array_chunk($userid_list, $num);
				$result       = [];
				$result_list  = [];
				foreach ($chunk_result as $list) {
					$args = [
						'open_kfid'   => $open_kfid,
						'userid_list' => $list
					];

					self::_HttpCall(self::WECHAT_KF_SERVICER_DEL, 'POST', $args);
					$result_list[] = $this->repJson;
				}
				$result = array_merge($result, ...$result_list);
			} else {
				$args = [
					'open_kfid'   => $open_kfid,
					'userid_list' => $userid_list
				];

				self::_HttpCall(self::WECHAT_KF_SERVICER_DEL, 'POST', $args);
				$result = $this->repJson;
			}

			return $result;
		}

		/**
		 * 获取接待人员列表
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfServicerList
		 *
		 * @param $open_kfid //客服帐号ID
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfServicerList ($open_kfid)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			$args = [
				'open_kfid' => $open_kfid
			];
			self::_HttpCall(self::WECHAT_KF_SERVICER_LIST, 'GET', $args);

			return $this->repJson;
		}

		/**
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfServiceStateGet
		 *
		 * @param $open_kfid       //客服帐号ID
		 * @param $external_userid //微信客户的external_userid
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfServiceStateGet ($open_kfid, $external_userid)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyStr($external_userid, 'external_userid');
			$args = [
				'open_kfid'       => $open_kfid,
				'external_userid' => $external_userid
			];

			self::_HttpCall(self::WECHAT_KF_SERVICER_STATE_GET, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 变更会话状态
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfServiceStateTrans
		 *
		 * @param $open_kfid       //客服帐号ID
		 * @param $external_userid //微信客户的external_userid
		 * @param $service_state   //变更的目标状态，状态定义和所允许的变更可参考概述中的流程图和表格
		 * @param $servicer_userid //接待人员的userid，当state=3时要求必填，接待人员须处于“正在接待”中。
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfServiceStateTrans ($open_kfid, $external_userid, $service_state, $servicer_userid)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyStr($external_userid, 'external_userid');
			Utils::checkIsUInt($service_state, 'service_state');

			$args = [
				'open_kfid'       => $open_kfid,
				'external_userid' => $external_userid,
				'service_state'   => $service_state
			];

			if ($service_state == 3) {
				Utils::checkNotEmptyStr($servicer_userid, 'servicer_userid');
				$args['servicer_userid'] = $servicer_userid;
			}

			self::_HttpCall(self::WECHAT_KF_SERVICER_STATE_TRANS, "POST", $args);

			return $this->repJson;
		}

		/**
		 * 获取消息
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfSyncMsg
		 *
		 * @param string $token  //回调事件返回的token字段，10分钟内有效；可不填，如果不填接口有严格的频率限制。不多于128字节
		 * @param int    $limit  //期望请求的数据量，默认值和最大值都为1000。注意：可能会出现返回条数少于limit的情况，需结合返回的has_more字段判断是否继续请求。
		 * @param string $cursor //上一次调用时返回的next_cursor，第一次拉取可以不填。不多于64字节
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \QyApiError
		 */
		public function kfSyncMsg (string $token = '', int $limit = 1000, string $cursor = '')
		{
			$args = [];
			if (!empty($token)) {
				$args = [
					'token' => $token,
					'limit' => $limit
				];
			}

			if (!empty($cursor)) {
				$args['cursor'] = $cursor;
			}

			self::_HttpCall(self::WECHAT_KF_SYNC_MSG, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 发送消息
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfSendMsg
		 *
		 * @param string $touser    //指定接收消息的客户UserID
		 * @param string $open_kfid //指定发送消息的客服帐号ID
		 * @param string $msgtype   //消息类型，此时固定为：text
		 * @param array  $content   //消息内容
		 * @param string $msgid     //指定消息ID
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfSendMsg (string $touser, string $open_kfid, string $msgtype, array $content, string $msgid = '')
		{
			Utils::checkNotEmptyStr($touser, 'touser');
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyStr($msgtype, 'msgtype');
			Utils::checkNotEmptyArray($content, 'content');
			$args = [
				'touser'    => $touser,
				'open_kfid' => $open_kfid,
				'msgtype'   => $msgtype,
				$msgtype    => $content,
			];

			if (!empty($msgid)) {
				$args['msgid'] = $msgid;
			}

			self::_HttpCall(self::WECHAT_KF_SEND_MSG, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 客户基本信息获取
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfCustomerBatchget
		 *
		 * @param array $external_userid_list //external_userid列表
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfCustomerBatchget (array $external_userid_list)
		{
			Utils::checkNotEmptyArray($external_userid_list, 'external_userid_list');
			$args = [
				'external_userid_list' => $external_userid_list
			];

			self::_HttpCall(self::WECHAT_KF_CUSTOMER_BATCHGET, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 获取配置的专员与客户群
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfCustomerGetUpgradeServiceConfig
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \QyApiError
		 */
		public function kfCustomerGetUpgradeServiceConfig ()
		{
			self::_HttpCall(self::WECHAT_KF_CUSTOMER_GET_UPGRADE_SERVICE_CONFIG, 'GET');

			return $this->repJson;
		}

		/**
		 * 为客户升级为专员
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfCustomerUpgradeServiceForMember
		 *
		 * @param string $open_kfid       //客服帐号ID
		 * @param string $external_userid //微信客户的external_userid
		 * @param string $userid          //服务专员的userid
		 * @param string $wording         //推荐语
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfCustomerUpgradeServiceForMember (string $open_kfid, string $external_userid, string $userid, string $wording)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyStr($external_userid, 'external_userid');
			Utils::checkNotEmptyStr($userid, 'userid');
			Utils::checkNotEmptyStr($wording, 'wording');
			$args = [
				'open_kfid'       => $open_kfid,
				'external_userid' => $external_userid,
				'type'            => 1,
				'member'          => [
					'userid'  => $userid,
					'wording' => $wording
				]
			];

			self::_HttpCall(self::WECHAT_KF_CUSTOMER_UPGRADE_SERVICE, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 为客户升级为客户群服务
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfCustomerUpgradeServiceForChat
		 *
		 * @param string $open_kfid       //客服帐号ID
		 * @param string $external_userid //微信客户的external_userid
		 * @param string $chat_id         //客户群id
		 * @param string $wording         //推荐语
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfCustomerUpgradeServiceForChat (string $open_kfid, string $external_userid, string $chat_id, string $wording)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyStr($external_userid, 'external_userid');
			Utils::checkNotEmptyStr($chat_id, 'chat_id');
			Utils::checkNotEmptyStr($wording, 'wording');
			$args = [
				'open_kfid'       => $open_kfid,
				'external_userid' => $external_userid,
				'type'            => 2,
				'groupchat'       => [
					'chat_id' => $chat_id,
					'wording' => $wording
				]
			];

			self::_HttpCall(self::WECHAT_KF_CUSTOMER_UPGRADE_SERVICE, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 为客户取消推荐
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: kfCustomerCancelUpgradeService
		 *
		 * @param string $open_kfid       //客服帐号ID
		 * @param string $external_userid //微信客户的external_userid
		 *
		 * @return null
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function kfCustomerCancelUpgradeService (string $open_kfid, string $external_userid)
		{
			Utils::checkNotEmptyStr($open_kfid, 'open_kfid');
			Utils::checkNotEmptyStr($external_userid, 'external_userid');
			$args = [
				'open_kfid'       => $open_kfid,
				'external_userid' => $external_userid
			];

			self::_HttpCall(self::WECHAT_KF_CUSTOMER_CANCEL_UPGRADE_SERVICE, 'POST', $args);

			return $this->repJson;
		}

		/**
		 * 将代开发应用获取的corpid转换为第三方应用获取的corpid
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: toOpenCorpid
		 *
		 * @param string $corpid // 代开发自建应用获取到的企业ID
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function toOpenCorpid (string $corpid)
		{
			Utils::checkNotEmptyStr($corpid, 'corpid');

			self::_HttpCall(self::TO_OPEN_CORPID, 'POST', ['corpid' => $corpid]);

			return $this->repJson;
		}

		/**
		 * 将代开发应用获取的userid转换为第三方应用获取的userid
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: useridToOpenuserid
		 *
		 * @param array $useridList // 代开发自建应用获取到的成员ID
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function useridToOpenuserid (array $useridList)
		{
			Utils::checkNotEmptyArray($useridList, 'userid_list');

			self::_HttpCall(self::USERID_TO_OPENUSERID, 'POST', ['userid_list' => $useridList]);

			return $this->repJson;
		}

		/**
		 * 将代开发自建应用获取到的external_userid转换为服务商第三方应用的external_userid。
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: useridToOpenuserid
		 *
		 * @param string $externalUserid // 代开发自建应用获取到的外部联系人ID
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function toServiceExternalUserid (string $externalUserid)
		{
			Utils::checkNotEmptyStr($externalUserid, 'external_userid');

			self::_HttpCall(self::TO_SERVICE_EXTERNAL_USERID, 'POST', ['external_userid' => $externalUserid]);

			return $this->repJson;
		}

		/**
		 * 批量转换external_userid
		 * File: vendor/fastwhale/yii2-we-work/Work.php
		 * Class: Work
		 * Function: getNewExternalUserid
		 *
		 * @param array $externalUseridList // 旧外部联系人id列表，最多不超过1000个
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function getNewExternalUserid (array $externalUseridList)
		{
			Utils::checkNotEmptyArray($externalUseridList, '$externalUseridList');

			self::_HttpCall(self::GET_NEW_EXTERNAL_USERID, 'POST', ['external_userid_list' => $externalUseridList]);

			return $this->repJson;
		}

		/**
		 * 换取momet_id
		 * */
		public function getMomentId ($job_id)
		{
			self::_HttpCall(self::GET_MOMENT_TASK_RESULT . '&jobid=' . $job_id);

			return $this->repJson;
		}

		/**
		 * userid转换
		 * 将代开发应用或第三方应用获取的密文open_userid转换为明文userid。
		 *
		 * @param $openUseridList
		 * @param $sourceAgentid
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function openuseridToUserid ($openUseridList, $sourceAgentid)
		{
			Utils::checkNotEmptyArray($openUseridList, 'open_userid_list');
			self::_HttpCall(self::OPENUSERID_TO_USERID, 'POST', ['open_userid_list' => $openUseridList, 'source_agentid' => $sourceAgentid]);

			return $this->repJson;
		}

		/**
		 * external_userid转换
		 * 将代开发应用或第三方应用获取的externaluserid转换成自建应用的externaluserid。
		 *
		 * @param $externalUserid
		 * @param $sourceAgentid
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function fromServiceExternalUserid ($externalUserid, $sourceAgentid)
		{
			Utils::checkNotEmptyStr($externalUserid, 'external_userid');
			self::_HttpCall(self::FROM_SERVICE_EXTERNAL_USERID, 'POST', ['external_userid' => $externalUserid, 'source_agentid' => $sourceAgentid]);

			return $this->repJson;
		}

		/**
		 * 获取打卡记录
		 *
		 * @param $opencheckindatatype
		 * @param $starttime
		 * @param $endtime
		 * @param $useridlist
		 *
		 * @return null
		 *
		 * @throws \HttpError
		 * @throws \NetWorkError
		 * @throws \ParameterError
		 * @throws \QyApiError
		 */
		public function Getcheckindata ($opencheckindatatype, $starttime, $endtime, $useridlist)
		{
			Utils::checkNotEmptyStr($opencheckindatatype, 'opencheckindatatype');
			Utils::checkNotEmptyStr($starttime, 'starttime');
			Utils::checkNotEmptyStr($endtime, 'endtime');
			Utils::checkNotEmptyArray($useridlist, 'useridlist');
			self::_HttpCall(self::GETCHECKINDATA, 'POST', ['opencheckindatatype' => $opencheckindatatype, 'starttime' => $starttime, 'endtime' => $endtime, 'useridlist' => $useridlist]);

			return $this->repJson;
		}

		public function Getapprovalinfo ($starttime, $endtime, $cursor = 0, $size = 100, $filters = [])
		{
			Utils::checkNotEmptyStr($starttime, 'starttime');
			Utils::checkNotEmptyStr($endtime, 'endtime');
			self::_HttpCall(self::GETAPPROVALINFO, 'POST', ['size' => $size, 'cursor' => $cursor, 'starttime' => $starttime, 'endtime' => $endtime, 'filters' => $filters]);

			return $this->repJson;
		}

		/* 创建获客链接 */
		public function ECAddCustomerAcquisition ($message)
		{
			self::_HttpCall(self::EXTERNAL_CREATE_LINK, 'POST', $message);

			return $this->repJson;
		}

		/* 编辑获客链接 */
		public function ECUpdateCustomerAcquisition ($message)
		{
			self::_HttpCall(self::EXTERNAL_UPDATE_LINK, 'POST', $message);

			return $this->repJson;
		}

		/* 删除获客链接 */
		public function ECDelCustomerAcquisition ($message)
		{
			self::_HttpCall(self::EXTERNAL_DELETE_LINK, 'POST', $message);

			return $this->repJson;
		}

		/* 获取获客链接信息 */
		public function ECGetCustomerAcquisition ($message)
		{
			self::_HttpCall(self::EXTERNAL_GET_LINK, 'POST', $message);

			return $this->repJson;
		}

		/* 查询链接使用详情 */
		public function ECGetCustomerAcquisitionStatistic ($message)
		{
			self::_HttpCall(self::EXTERNAL_GET_LINK_STATISTIC, 'POST', $message);

			return $this->repJson;
		}

		/* 查询剩余使用量 */
		public function ECGetCustomerAcquisitionQuota ()
		{
			self::_HttpCall(self::EXTERNAL_GET_LINK_QUOTA, 'GET');

			return $this->repJson;
		}

		/* 获取获客链接列表 */
		public function ECGetCustomerAcquisitionListLink ($message)
		{
			self::_HttpCall(self::EXTERNAL_GET_LIST_LINK, 'POST', $message);

			return $this->repJson;
		}

		/* 获取获客客户列表 */
		public function ECGetCustomerAcquisitionCustomer ($message)
		{
			self::_HttpCall(self::EXTERNAL_GET_LINK_CUSTOMER, 'POST', $message);

			return $this->repJson;
		}

		/* 获取打卡日报数据 */
		public function Getcheckindaydata ($starttime, $endtime, $useridlist)
		{
			Utils::checkNotEmptyStr($starttime, 'starttime');
			Utils::checkNotEmptyStr($endtime, 'endtime');
			Utils::checkNotEmptyArray($useridlist, 'useridlist');
			self::_HttpCall(self::GETCHECKINDAYDATA, 'POST', ['starttime' => $starttime, 'endtime' => $endtime, 'useridlist' => $useridlist]);

			return $this->repJson;
		}

		public function unionidToExternalUserid ($unionid, $openid, $subject_type = 0)
		{
			Utils::checkNotEmptyStr($unionid, "unionid");
			Utils::checkNotEmptyStr($openid, "openid");

			$args = [
				'unionid'      => $unionid,
				'openid'       => $openid,
				'subject_type' => $subject_type,
			];
			self::_HttpCall(self::UNIONID_TO_EXTERNAL_USERID, 'POST', $args);

			return $this->repJson;
		}

		/* 创建预约会议*/
		public function ECMeetingCreate ($data)
		{
			self::_HttpCall(self::MEETING_CREATE, 'POST', $data);

			return $this->repJson;
		}

		/* 修改预约会议 */
		public function ECMeetingUpdate ($data)
		{
			self::_HttpCall(self::MEETING_UPDATE, 'POST', $data);

			return $this->repJson;
		}

		/* 取消预约会议 */
		public function ECMeetingCancel ($data)
		{
			self::_HttpCall(self::MEETING_CANCEL, 'POST', $data);

			return $this->repJson;
		}

		/* 获取会议详情 */
		public function ECGetMeetingInfo ($data)
		{
			self::_HttpCall(self::GET_MEETING_INFO, 'POST', $data);

			return $this->repJson;
		}

		/* 获取成员会议ID列表 */
		public function ECGetUserMeetingId ($data)
		{
			self::_HttpCall(self::GET_USER_MEETING_ID, 'POST', $data);

			return $this->repJson;
		}

		/* 服务商会话存档  设置公钥 */
		public function SetPublicKey ($data)
		{
			self::_HttpCall(self::SET_PUBLIC_KEY, 'POST', $data);

			return $this->repJson;
		}

		/* 服务商会话存档 获取授权存档的成员列表 */
		public function GetAuthUserList ($data)
		{
			self::_HttpCall(self::GET_AUTH_USER_LIST, 'POST', $data);

			return $this->repJson;
		}

		/* 服务商会话存档 设置专区接收回调事件 */
		public function SetReceiveCallback ($data)
		{
			self::_HttpCall(self::SET_RECEIVE_CALLBACK, 'POST', $data);

			return $this->repJson;
		}

		/* 服务商会话存档  应用同步调用专区程序 */
		public function SyncCallProgram ($data)
		{
			self::_HttpCall(self::SYNC_CALL_PROGRAM, 'POST', $data);

			return $this->repJson;
		}

		/* 服务商会话存档  创建专区程序调用任务 */
		public function AsyncCallProgram ($data)
		{
			self::_HttpCall(self::ASYNC_CALL_PROGRAM, 'POST', $data);

			return $this->repJson;
		}

		/* 服务商会话存档  获取专区程序任务结果 */
		public function AsyncProgramResult ($data)
		{
			self::_HttpCall(self::ASYNC_PROGRAM_RESULT, 'POST', $data);

			return $this->repJson;
		}

		/* 获取应用权限详情 代开发自建应用/第三方应用 */
		public function GetPermissions ($data)
		{
			self::_HttpCall(self::GET_PERMISSIONS, 'POST', $data);

			return $this->repJson;
		}

		/* 设置成员会话组件敏感信息隐藏配置  */
		public function GetCorpAuthInfo ($data)
		{
			self::_HttpCall(self::GET_CORP_AUTH_INFO, 'POST', $data);

			return $this->repJson;
		}

		/* 获取成员会话组件敏感信息隐藏配置  */
		public function GetHideSensitiveinfoConfig ($data)
		{
			self::_HttpCall(self::GET_HIDE_SENSITIVEINFO_CONFIG, 'POST', $data);

			return $this->repJson;
		}

		/* 设置成员会话组件敏感信息隐藏配置  */
		public function SetHideSensitiveinfoConfig ($data)
		{
			self::_HttpCall(self::SET_HIDE_SENSITIVEINFO_CONFIG, 'POST', $data);

			return $this->repJson;
		}

		/* 申请群ID的升级 */
		public function ApplyToUpgradeChatid ($data)
		{
			self::_HttpCall(self::APPLY_TO_UPGRADE_CHATID, 'POST', $data);

			return $this->repJson;
		}

		/* 群ID转换接口 */
		public function IdconvertChatid ($data)
		{
			self::_HttpCall(self::IDCONVERT_CHATID, 'POST', $data);

			return $this->repJson;
		}

		/* 对所有新授权企业升级群ID */
		public function UpgradeChatidForNewCorp ($data)
		{
			self::_HttpCall(self::UPGRADE_CHATID_FOR_NEW_CORP, 'POST', $data);

			return $this->repJson;
		}

		/* 更新模版卡片消息 */
		public function UpdateTemplateCard ($message, &$invalidUserIdList, &$invalidPartyIdList, &$invalidTagIdList)
		{
			self::_HttpCall(self::UPDATE_TEMPLATE_CARD, 'POST', $message);

			$this->getInvalidList($invalidUserIdList, $invalidPartyIdList, $invalidTagIdList);

			return $this->repJson;
		}

		/* 服务商会话存档 上传临时文件到专区 */
		public function ChatdataMediaUpload ($filePath, $type, $plus = [])
		{
			Utils::checkNotEmptyStr($filePath, "filePath");
			Utils::checkNotEmptyStr($type, "type");

			if (!file_exists($filePath)) {
				throw new \QyApiError("file not exists");
			}
			$fileName = !empty($plus['file_name']) ? $plus['file_name'] : basename($filePath);
			// 兼容php5.3-5.6 curl模块的上传操作
			$args = [];
			if (class_exists('\CURLFile')) {
				$args = ['media' => new \CURLFile(realpath($filePath), 'application/octet-stream', $fileName)];
			} else {
				$args = ['media' => '@' . realpath($filePath)];
			}

			self::_HttpCall(self::CHATDATA_UPLOAD_MEDIA . '&type=' . $type, 'POST', $args, true, true);

			return $this->repJson["media_id"];
		}
	}
