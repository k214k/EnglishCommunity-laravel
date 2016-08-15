<?php

namespace App\Http\Controllers\Api;

use App\Http\Model\Comment;
use App\Http\Model\LikeRecord;
use App\Http\Model\Tweets;
use App\Http\Model\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;

class TweetsController extends BaseController
{
    /**
     * @api {get} /getTweetsList.api 动弹列表
     * @apiDescription 获取动弹列表,可根据参数返回不同的数据
     * @apiGroup Trends
     * @apiPermission none
     * @apiParam {String} [type] 返回类型 默认new, new最新 hot热门 me我的
     * @apiParam {Number} [page] 页码,默认当然是第1页
     * @apiParam {Number} [count] 每页数量,默认10条
     * @apiParam {Number} [user_id] 访客用户id,type为me,这个字段必须传,游客传0
     * @apiVersion 0.0.1
     * @apiSuccessExample {json} Success-Response:
     *       {
     *       }
     * @apiErrorExample {json} Error-Response:
     *     {
     *           "status": "error",
     *           "code": 404,
     *           "message": "查询动弹列表失败"
     *      }
     */
    public function getTweetsList(Request $request)
    {
        $type = isset($request->type) ? $request->type : 'new';           // 请求类型
        $count = isset($request->count) ? (int)$request->count : 10;      // 单页数量
        $user_id = isset($request->user_id) ? (int)$request->user_id : 0; // 请求用户

        // 根据参数过滤数据
        $tweets = Tweets::orderBy('id', 'desc');
        if ($type === 'new') {
            $tweets = $tweets->paginate($count);
        } elseif ($type === 'hot') {
            $tweets = $tweets->orderBy('view', 'desc')->paginate($count);
        } elseif ($type === 'me') {
            $tweets = $tweets->where('user_id', $user_id)->paginate($count);
        }

        // 只读一次到内存,节省资源
        $comments = Comment::where('type', 'tweet')->get();
        $likeRecords = LikeRecord::where('type', 'tweet')->get();

        // 返回数据
        $result = null;

        // 没有查询到数据
        $data = $tweets->all();
        if (count($data) == 0) {
            return $this->respondWithErrors('没有查询到动弹列表数据');
        }

        // 向单条数据里添加数据
        foreach ($data as $key => $value) {
            // 动弹作者
            $user = User::find($value->user_id);
            // 访客对这条动弹的赞记录
            $userLikeRecord = $likeRecords->where('source_id', $value->id)->where('user_id', $user_id)->first();

            $result[$key]['id'] = $value->id;
            $result[$key]['appClient'] = $value->app_client;
            $result[$key]['content'] = $value->content;
            $result[$key]['commentCount'] = $comments->where('source_id', $value->id)->count();
            $result[$key]['likeCount'] = $likeRecords->where('source_id', $value->id)->count();
            $result[$key]['liked'] = isset($userLikeRecord) ? 1 : 0;
            $result[$key]['publishTime'] = (string)$value->created_at->timestamp;
            $result[$key]['author'] = [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'avatar' => url($user->avatar),
            ];

            // 有图片才拆分
            if (! empty($value->photos)) {
                $photos = explode(',', $value->photos);
                $photoThumbs = explode(',', $value->photo_thumbs);
                $images = null;

                foreach ($photos as $k => $v) {
                    $images[$k]['href'] = $photos[$k];
                    $images[$k]['thumb'] = $photoThumbs[$k];
                }
                $result[$key]['images'] = $images;
            }

        }

        return $this->respondWithSuccess([
            'pageInfo' => [
                'total' => $tweets->total(),
                'currentPage' => $tweets->currentPage(),
            ],
            'data' => $result,
        ], '查询动弹列表成功');
    }

    /**
     * @api {get} /getTweetsDetail.api 动弹详情
     * @apiDescription 获取动弹详情,获取动弹赞列表、评论列表是其他接口
     * @apiGroup Trends
     * @apiPermission none
     * @apiParam {Number} trends_id 动弹id
     * @apiParam {Number} [user_id] 访客用户id
     * @apiVersion 0.0.1
     * @apiSuccessExample {json} Success-Response:
     *       {
     *       }
     * @apiErrorExample {json} Error-Response:
     *       {
     *           "status": "error",
     *           "code": 400,
     *           "message": "trends_id无效"
     *       }
     */
    public function getTweetsDetail(Request $request)
    {
//        $user_id = isset($request->user_id) ? (int)$request->user_id : 0;       // 请求用户
//        $trends_id = isset($request->trends_id) ? (int)$request->trends_id : 0; // 动弹id
//        if ($trends_id == 0) {
//            return $this->respondWithErrors('trends_id无效', 400);
//        }
//
//        $trends = Trends::find($trends_id);   // 当前动弹
//        $user = User::find($trends->user_id); // 当前动弹的作者
//        $user_favoriteRecord = FavoriteRecord::where('type', 'trends')->where('source_id', $trends->id)->where('user_id', $user_id)->first();
//
//        // 浏览量递增
//        $trends->increment('view');
//
//        $data = $trends->toArray();
//        $data['comment_count'] = Comment::where('type', 'trends')->where('source_id', $trends->id)->count();
//        $data['favorite_count'] = FavoriteRecord::where('type', 'trends')->where('source_id', $trends->id)->count();
//        $data['is_favorite'] = isset($user_favoriteRecord) ? 1 : 0;
//        $data['user_nickname'] = $user->nickname;
//        $data['user_avatar'] = $user->avatar;
//
//        return $this->respondWithSuccess($data, '查询动弹详情成功');
    }

    /**
     * @api {post} /postTrends.api 发布动弹
     * @apiDescription 发布一条新的动弹
     * @apiGroup Trends
     * @apiPermission none
     * @apiParam {Number} user_id 作者用户id
     * @apiParam {String} content 动弹内容
     * @apiParam {File} [photo] 配图,这个字段以图片上传方式提交即可
     * @apiVersion 0.0.1
     * @apiSuccessExample {json} Success-Response:
     *       {
     *           "status": "success",
     *           "code": 200,
     *           "message": "发布动弹成功",
     *           "data": null
     *       }
     * @apiErrorExample {json} Error-Response:
     *     {
     *           "status": "error",
     *           "code": 400,
     *           "message": "发布动弹失败"
     *      }
     */
    public function postTrends(Request $request)
    {
//        $validator = Validator::make($request->all(), [
//            'user_id' => ['required'],
//            'content' => ['required']
//        ], [
//            'user_id.required' => 'user_id不能为空',
//            'content.required' => '发布内容不能为空'
//        ]);
//        if ($validator->fails()) {
//            return $this->respondWithFailedValidation($validator);
//        }


//        return $this->respondWithSuccess($request->get('photo'));
//        $image = Image::make($request->file())->resize(300, 200);
//        dd($image);

//        dd($file);

//        if ($file->isValid()) {
//            $file->move('temp', $file->getClientOriginalName());
//            $tempPath = 'temp/'.$file->getClientOriginalName();
//            return $tempPath;
//        }


    }


}
