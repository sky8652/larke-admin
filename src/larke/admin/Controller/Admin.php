<?php

declare (strict_types = 1);

namespace Larke\Admin\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use Larke\Admin\Model\Admin as AdminModel;
use Larke\Admin\Model\AuthGroupAccess as AuthGroupAccessModel;
use Larke\Admin\Support\Password as PasswordService;
use Larke\Admin\Repository\Admin as AdminRepository;

/**
 * 管理员
 *
 * @title 管理员
 * @desc 系统管理员管理
 * @order 105
 * @auth true
 * @slug larke-admin.admin
 *
 * @create 2020-10-23
 * @author deatil
 */
class Admin extends Base
{
    /**
     * 列表
     *
     * @title 管理员列表
     * @desc 系统管理员列表
     * @order 1051
     * @auth true
     *
     * @param  Request  $request
     * @return Response
     */
    public function index(Request $request)
    {
        $start = (int) $request->input('start', 0);
        $limit = (int) $request->input('limit', 10);
        
        $order = $this->formatOrderBy($request->input('order', 'ASC'));
        
        $wheres = [];
        
        $startTime = $this->formatDate($request->input('start_time'));
        if ($startTime !== false) {
            $wheres[] = ['create_time', '>=', $startTime];
        }
        
        $endTime = $this->formatDate($request->input('end_time'));
        if ($endTime !== false) {
            $wheres[] = ['create_time', '<=', $endTime];
        }
        
        $status = $this->switchStatus($request->input('status'));
        if ($status !== false) {
            $wheres[] = ['status', $status];
        }
        
        $orWheres = [];
        
        $searchword = $request->input('searchword', '');
        if (! empty($searchword)) {
            $orWheres = [
                ['name', 'like', '%'.$searchword.'%'],
                ['nickname', 'like', '%'.$searchword.'%'],
                ['email', 'like', '%'.$searchword.'%'],
            ];
        }
        
        $query = AdminModel::withAccess()
            ->orWheres($orWheres)
            ->wheres($wheres);
        
        $total = $query->count(); 
        $list = $query->offset($start)
            ->limit($limit)
            ->select([
                'id', 
                'name', 
                'nickname', 
                'email', 
                'avatar', 
                'is_root', 
                'status', 
                'last_active', 
                'last_ip',
                'create_time', 
                'create_ip'
            ])
            ->orderBy('create_time', $order)
            ->get()
            ->toArray(); 
        
        return $this->success(__('获取成功'), [
            'start' => $start,
            'limit' => $limit,
            'total' => $total,
            'list' => $list,
        ]);
    }
    
    /**
     * 详情
     *
     * @title 管理员详情
     * @desc 系统管理员详情
     * @order 1052
     * @auth true
     *
     * @param string $id
     * @return Response
     */
    public function detail(string $id)
    {
        if (empty($id)) {
            return $this->error(__('管理员ID不能为空'));
        }
        
        $info = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->with(['groups'])
            ->select([
                'id', 
                'name', 
                'nickname', 
                'email', 
                'avatar', 
                'introduce', 
                'is_root', 
                'status', 
                'last_active', 
                'last_ip',
                'create_time', 
                'create_ip'
            ])
            ->first();
        if (empty($info)) {
            return $this->error(__('管理员信息不存在'));
        }
        
        $adminGroups = $info['groups'];
        unset($info['groupAccesses'], $info['groups']);
        $info['groups'] = collect($adminGroups)
            ->map(function($data) {
                return [
                    'id' => $data['id'],
                    'parentid' => $data['parentid'],
                    'title' => $data['title'],
                    'description' => $data['description'],
                ];
            });
        
        return $this->success(__('获取成功'), $info);
    }
    
    /**
     * 权限
     *
     * @title 管理员权限
     * @desc 系统管理员权限
     * @order 1053
     * @auth true
     *
     * @param string $id
     * @return Response
     */
    public function rules(string $id)
    {
        if (empty($id)) {
            return $this->error(__('管理员ID不能为空'));
        }
        
        $info = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->with(['groups'])
            ->select([
                'id', 
                'name', 
                'nickname',
            ])
            ->first();
        if (empty($info)) {
            return $this->error(__('管理员信息不存在'));
        }
        
        $groupids = collect($info['groups'])->pluck('id')->toArray();
        
        $rules = AdminRepository::getRules($groupids);
        return $this->success(__('获取成功'), [
            'list' => $rules,
        ]);
    }
    
    /**
     * 删除
     *
     * @title 管理员删除
     * @desc 系统管理员删除
     * @order 1054
     * @auth true
     *
     * @param string $id
     * @return Response
     */
    public function delete(string $id)
    {
        if (empty($id)) {
            return $this->error(__('管理员ID不能为空'));
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($id == $adminid) {
            return $this->error(__('你不能删除你自己'));
        }
        
        $info = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($info)) {
            return $this->error(__('管理员信息不存在'));
        }
        
        if ($info['id'] == config('larkeadmin.auth.admin_id')) {
            return $this->error(__('当前管理员不能被删除'));
        }
        
        $deleteStatus = $info->delete();
        if ($deleteStatus === false) {
            return $this->error(__('管理员删除失败'));
        }
        
        return $this->success(__('管理员删除成功'));
    }
    
    /**
     * 添加
     *
     * @title 管理员添加
     * @desc 系统管理员添加
     * @order 1055
     * @auth true
     *
     * @param  Request  $request
     * @return Response
     */
    public function create(Request $request)
    {
        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => 'required|max:20|unique:'.AdminModel::class,
            'nickname' => 'required|max:150',
            'email' => 'required|email|max:100|unique:'.AdminModel::class,
            'introduce' => 'required|max:500',
            'status' => 'required',
        ], [
            'name.required' => __('管理员不能为空'),
            'name.unique' => __('管理员已经存在'),
            'nickname.required' => __('昵称不能为空'),
            'email.required' => __('邮箱不能为空'),
            'email.email' => __('邮箱格式错误'),
            'email.unique' => __('邮箱已经存在'),
            'introduce.required' => __('简介不能为空'),
            'introduce.max' => __('简介字数超过了限制'),
            'status.required' => __('状态选项不能为空'),
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        
        $insertData = [
            'name' => $data['name'],
            'nickname' => $data['nickname'],
            'email' => $data['email'],
            'introduce' => $data['introduce'],
            'status' => ($data['status'] == 1) ? 1 : 0,
        ];
        if (!empty($data['avatar'])) {
            $validatorAvatar = Validator::make([
                'avatar' => $data['avatar'],
            ], [
                'avatar' => 'required|size:32',
            ], [
                'avatar.required' => __('头像数据不能为空'),
                'avatar.size' => __('头像数据错误'),
            ]);

            if ($validatorAvatar->fails()) {
                return $this->error($validatorAvatar->errors()->first());
            }
            
            $insertData['avatar'] = $data['avatar'];
        }
        
        $admin = AdminModel::create($insertData);
        if ($admin === false) {
            return $this->error(__('添加信息失败'));
        }
        
        // 用户组默认取当前用户的用户组的其中之一
        $groupIds = app('larke.admin.admin')->getGroupids();
        if (count($groupIds) > 0) {
            AuthGroupAccessModel::create([
                'admin_id' => $admin->id,
                'group_id' => $groupIds[0],
            ]);
        }
        
        return $this->success(__('添加信息成功'), [
            'id' => $admin->id,
        ]);
    }
    
    /**
     * 更新
     *
     * @title 管理员更新
     * @desc 系统管理员更新
     * @order 1056
     * @auth true
     *
     * @param string $id
     * @param Request $request
     * @return Response
     */
    public function update(string $id, Request $request)
    {
        if (empty($id)) {
            return $this->error(__('管理员ID不能为空'));
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($id == $adminid) {
            return $this->error(__('你不能修改自己的信息'));
        }
        
        $adminInfo = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($adminInfo)) {
            return $this->error(__('要修改的管理员不存在'));
        }
        
        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => 'required|max:20',
            'nickname' => 'required|max:150',
            'email' => 'required|email|max:100',
            'introduce' => 'required|max:500',
            'status' => 'required',
        ], [
            'name.required' => __('管理员不能为空'),
            'nickname.required' => __('昵称不能为空'),
            'email.required' => __('邮箱不能为空'),
            'email.email' => __('邮箱格式错误'),
            'introduce.required' => __('简介不能为空'),
            'introduce.max' => __('简介字数超过了限制'),
            'status.required' => __('状态选项不能为空'),
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        
        $nameInfo = AdminModel::orWhere(function($query) use($id, $data) {
                $query->where('id', '!=', $id);
                $query->where('name', '=', $data['name']);
            })
            ->orWhere(function($query) use($id, $data) {
                $query->where('id', '!=', $id);
                $query->where('email', '=', $data['email']);
            })
            ->first();
        if (!empty($nameInfo)) {
            return $this->error(__('要修改成的管理管理员或者邮箱已经存在'));
        }
        
        $updateData = [
            'name' => $data['name'],
            'nickname' => $data['nickname'],
            'email' => $data['email'],
            'introduce' => $data['introduce'],
            'status' => ($data['status'] == 1) ? 1 : 0,
        ];
        if (!empty($data['avatar'])) {
            $validatorAvatar = Validator::make([
                'avatar' => $data['avatar'],
            ], [
                'avatar' => 'required|size:32',
            ], [
                'avatar.required' => __('头像数据不能为空'),
                'avatar.size' => __('头像数据错误'),
            ]);

            if ($validatorAvatar->fails()) {
                return $this->error($validatorAvatar->errors()->first());
            }
            
            $updateData['avatar'] = $data['avatar'];
        }
        
        // 更新信息
        $status = $adminInfo->update($updateData);
        if ($status === false) {
            return $this->error(__('信息修改失败'));
        }
        
        return $this->success(__('信息修改成功'));
    }

    /**
     * 修改头像
     *
     * @title 修改头像
     * @desc 系统管理员修改头像
     * @order 1057
     * @auth true
     *
     * @param string $id
     * @param Request $request
     * @return Response
     */
    public function updateAvatar(string $id, Request $request)
    {
        if (empty($id)) {
            return $this->error(__('管理员ID不能为空'));
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($id == $adminid) {
            return $this->error(__('你不能修改自己的头像'));
        }
        
        $adminInfo = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($adminInfo)) {
            return $this->error(__('要修改的管理员不存在'));
        }
        
        $data = $request->only(['avatar']);
        
        $validator = Validator::make($data, [
            'avatar' => 'required|size:32',
        ], [
            'avatar.required' => __('头像数据不能为空'),
            'avatar.size' => __('头像数据错误'),
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        
        $status = $adminInfo->updateAvatar($data['avatar']);
        if ($status === false) {
            return $this->error(__('修改信息失败'));
        }
        
        return $this->success(__('修改头像成功'));
    }
    
    /**
     * 修改密码
     *
     * @title 修改密码
     * @desc 系统管理员修改密码
     * @order 1058
     * @auth true
     *
     * @param string $id
     * @param Request $request
     * @return Response
     */
    public function updatePasssword(string $id, Request $request)
    {
        if (empty($id)) {
            return $this->error(__('管理员ID不能为空'));
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($id == $adminid) {
            return $this->error(__('你不能修改自己的密码'));
        }
        
        $adminInfo = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($adminInfo)) {
            return $this->error(__('要修改的管理员不存在'));
        }

        // 密码长度错误
        $password = $request->input('password');
        if (strlen($password) != 32) {
            return $this->error(__('密码格式错误'));
        }

        // 新密码
        $newPasswordInfo = (new PasswordService())
            ->withSalt(config('larkeadmin.passport.password_salt'))
            ->encrypt($password); 

        // 更新信息
        $status = $adminInfo->update([
                'password' => $newPasswordInfo['password'],
                'password_salt' => $newPasswordInfo['encrypt'],
            ]);
        if ($status === false) {
            return $this->error(__('密码修改失败'));
        }
        
        return $this->success(__('密码修改成功'));
    }
    
    /**
     * 启用
     *
     * @title 管理员启用
     * @desc 系统管理员启用
     * @order 1059
     * @auth true
     *
     * @param string $id
     * @return Response
     */
    public function enable(string $id)
    {
        if (empty($id)) {
            return $this->error(__('ID不能为空'));
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($id == $adminid) {
            return $this->error(__('你不能修改自己的管理员'));
        }
        
        $info = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($info)) {
            return $this->error(__('管理员不存在'));
        }
        
        if ($info->status == 1) {
            return $this->error(__('管理员已启用'));
        }
        
        $status = $info->enable();
        if ($status === false) {
            return $this->error(__('启用失败'));
        }
        
        return $this->success(__('启用成功'));
    }
    
    /**
     * 禁用
     *
     * @title 管理员禁用
     * @desc 系统管理员禁用
     * @order 10510
     * @auth true
     *
     * @param string $id
     * @return Response
     */
    public function disable(string $id)
    {
        if (empty($id)) {
            return $this->error(__('ID不能为空'));
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($id == $adminid) {
            return $this->error(__('你不能修改自己的管理员'));
        }
        
        $info = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($info)) {
            return $this->error(__('管理员不存在'));
        }
        
        if ($info->status == 0) {
            return $this->error(__('管理员已禁用'));
        }
        
        $status = $info->disable();
        if ($status === false) {
            return $this->error(__('禁用失败'));
        }
        
        return $this->success(__('禁用成功'));
    }
    
    /**
     * 退出
     *
     * @title 管理员退出
     * @desc 系统管理员退出
     * @order 10511
     * @auth true
     * 
     * 添加用户的 refreshToken 到黑名单
     *
     * @param string $refreshToken
     * @return Response
     */
    public function logout(string $refreshToken)
    {
        if (empty($refreshToken)) {
            return $this->error(__('refreshToken不能为空'));
        }
        
        if (app('larke.admin.cache')->has(md5($refreshToken))) {
            return $this->error(__('refreshToken已失效'));
        }
        
        try {
            $refreshJwt = app('larke.admin.jwt')
                ->withJti(config('larkeadmin.passport.refresh_token_id'))
                ->withToken($refreshToken)
                ->decode();
            
            if (! ($refreshJwt->validate() && $refreshJwt->verify())) {
                return $this->error(__('refreshToken已过期'));
            }
            
            $refreshAdminid = $refreshJwt->getData('adminid');
            
            // 过期时间
            $refreshTokenExpiresIn = $refreshJwt->getClaim('exp') - $refreshJwt->getClaim('iat');
        } catch(\Exception $e) {
            return $this->error($e->getMessage());
        }
        
        $adminid = app('larke.admin.admin')->getId();
        if ($refreshAdminid == $adminid) {
            return $this->error(__('你不能退出你的管理员'));
        }
        
        // 添加缓存黑名单
        app('larke.admin.cache')->add(md5($refreshToken), time(), $refreshTokenExpiresIn);
        
        return $this->success(__('退出成功'));
    }
    
    /**
     * 授权
     *
     * @title 管理员授权
     * @desc 系统管理员授权
     * @order 10512
     * @auth true
     *
     * @param string $id
     * @param Request $request
     * @return Response
     */
    public function access(string $id, Request $request)
    {
        if (empty($id)) {
            return $this->error(__('ID不能为空'));
        }
        
        $info = AdminModel::withAccess()
            ->where('id', '=', $id)
            ->first();
        if (empty($info)) {
            return $this->error(__('信息不存在'));
        }
        
        AuthGroupAccessModel::where('admin_id', '=', $id)
            ->get()
            ->each
            ->delete();
        
        $access = $request->input('access');
        if (!empty($access)) {
            $groupIds = app('larke.admin.admin')->getGroupChildrenIds();
            $accessIds = explode(',', $access);
            $accessIds = collect($accessIds)->unique();
            
            // 取交集
            if (!app('larke.admin.admin')->isAdministrator()) {
                $intersectAccess = array_intersect_assoc($groupIds, $accessIds);
            } else {
                $intersectAccess = $accessIds;
            }
            
            $accessData = [];
            foreach ($intersectAccess as $value) {
                AuthGroupAccessModel::create([
                    'admin_id' => $id,
                    'group_id' => $value,
                ]);
            }
        }
        
        return $this->success(__('授权分组成功'));
    }
    
}