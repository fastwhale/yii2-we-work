<?php

	namespace fastwhale\yii2\weWork;

	require_once "components/errorInc/error.inc.php";

	use app\util\WorkUtils;
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

	class ChainWork extends BaseWork
	{
		/**
		 * 主企业本地企业ID
		 *
		 * @var string
		 */
		public $work_corp_id;
		/**
		 * 每个下游企业都拥有唯一的corpid
		 *
		 * @var string
		 */
		public $corpid;
		/**
		 * 已授权的下级/下游企业应用ID
		 * @var int
		 */
		public $agentid;
		/**
		 * access_token是企业后台去企业微信的后台获取信息时的重要票据，由下游corpid和已授权的下级/下游企业应用agentid产生。所有接口在通信时都需要携带此信息用于验证接口的访问权限
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
		protected $cachePrefix = 'cache_work_chain';

		/**
		 * 企业进行自定义开发调用, 请传参 corpid + agentid, 不用关心accesstoken，本类会自动获取并刷新
		 *
		 * @throws \ParameterError
		 */
		public function init ()
		{
			Utils::checkNotEmptyStr($this->corpid, 'corpid');
			Utils::checkIsUInt($this->agentid, 'agentid');
			Utils::checkIsUInt($this->work_corp_id, 'work_corp_id');
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
			if (empty($this->work_corp_id) || empty($this->corpid) || empty($this->agentid)) {
				throw new \ParameterError("invalid work_corp_id or corpid or agentid");
			}
			$time    = time();
			$workApi = WorkUtils::getWorkApi($this->work_corp_id, WorkUtils::SYNC_API);
			$data    = $workApi->CGGettoken(['corpid' => $this->corpid, 'agentid' => $this->agentid, 'business_type' => 1]);

			$data['expire'] = $time + $data["expires_in"];
			$this->setCache('access_token', $data, $data['expires_in']);

			return $data;
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


		/* 获取指定的应用详情 */
		public function AgentGet ($agentId)
		{
			self::_HttpCall(self::AGENT_GET, 'GET', ['agentid' => $agentId]);

			return $this->repJson;
		}

		/* 读取成员 */
		public function userGet ($userId)
		{
			Utils::checkNotEmptyStr($userId, 'userid');
			self::_HttpCall(self::USER_GET, 'GET', ['userid' => $userId]);

			return User::parseFromArray($this->repJson);
		}


	}
