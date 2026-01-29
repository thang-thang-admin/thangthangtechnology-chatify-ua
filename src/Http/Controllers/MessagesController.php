<?php

namespace Chatify\Http\Controllers;

use Google_Client;
use App\Models\CustomerInfo;
use App\Models\User as Admin;
use App\Models\FcmTokenKey;
use Illuminate\Support\Str;
use App\Models\CustomerInfo as User;
use Illuminate\Http\Request;
use App\Models\ChMessage as Message;
use Illuminate\Http\JsonResponse;
use App\Models\ChFavorite as Favorite;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Illuminate\Support\Facades\Request as FacadesRequest;


class MessagesController extends Controller
{
    protected $perPage = 30;

    /**
     * Authenticate the connection for pusher
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function pusherAuth(Request $request)
    {
        return Chatify::pusherAuth(
            $request->user(),
            Auth::guard('sanctum')->user(),
            $request['channel_name'],
            $request['socket_id']
        );
    }

    /**
     * Returning the view of the app with the required data.
     *
     * @param int $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function index($id = null)
    {
        $messenger_color = Auth::guard('sanctum')->user()->messenger_color;
        return view('Chatify::pages.app', [
            'id' => $id ?? 0,
            'messengerColor' => $messenger_color ? $messenger_color : Chatify::getFallbackColor(),
            'dark_mode' => Auth::guard('sanctum')->user()->dark_mode < 1 ? 'light' : 'dark',
        ]);
    }


    /**
     * Fetch data (user, favorite.. etc).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function idFetchData(Request $request)
    {
        $favorite = Chatify::inFavorite($request['id']);
        $fetch = User::where('id', $request['id'])->first();
        if ($fetch) {
            $userAvatar = Chatify::getUserWithAvatar($fetch)->avatar;
        }
        return Response::json([
            'favorite' => $favorite,
            'fetch' => $fetch ?? null,
            'user_avatar' => $userAvatar ?? null,
        ]);
    }

    /**
     * This method to make a links for the attachments
     * to be downloadable.
     *
     * @param string $fileName
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|void
     */
    public function download($fileName)
    {
        $filePath = config('chatify.attachments.folder') . '/' . $fileName;
        if (Chatify::storage()->exists($filePath)) {
            return Chatify::storage()->download($filePath);
        }
        return abort(404, "Sorry, File does not exist in our server or may have been deleted!");
    }

    /**
     * Send a message to database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function send(Request $request)
    {
        // default variables
        $error = (object)[
            'status' => 0,
            'message' => null
        ];
        $attachment = null;
        $attachment_title = null;

        // if there is attachment [file]
        if ($request->hasFile('file') || $request->hasFile('audio_data')) {
            // allowed extensions
            $allowed_images = Chatify::getAllowedImages();
            $allowed_files  = Chatify::getAllowedFiles();
            $allowed        = array_merge($allowed_images, $allowed_files);

            if ($request->type == 'audio') {
                $file = $request->file('audio_data');
            } else {
                $file = $request->file('file');
            }
            // check file size
            if ($file->getSize() < Chatify::getMaxUploadSize()) {
                // if (in_array(strtolower($file->extension()), $allowed)) {
                //     // get attachment name
                //     $attachment_title = $file->getClientOriginalName();
                //     // upload attachment and store the new name
                //     $attachment = Str::uuid() . "." . $file->extension();
                //     $file->storeAs(config('chatify.attachments.folder'), $attachment, config('chatify.storage_disk_name'));
                // } else {
                //     $error->status = 1;
                //     $error->message = "File extension not allowed!";
                // }
                $extension = strtolower($file->extension());

                if (in_array($extension, $allowed)) {
                    // get original file name
                    $attachment_title = $file->getClientOriginalName();

                    // create a unique file name
                    $uniqueName = Str::uuid() . '.' . $extension;
                    
                    // upload to s3
                    $filePath = $file->storeAs(
                        'profile_files', // e.g., 'attachments'
                        $uniqueName,
                        config('chatify.storage_disk_name')    // e.g., 's3'
                    );

                    // get the full URL to store in database
                    $attachment = Storage::disk(config('chatify.storage_disk_name'))->url($filePath);
                } else {
                    $error->status = 1;
                    $error->message = "File extension not allowed!";
                }
            } else {
                $error->status = 1;
                $error->message = "File size you are trying to upload is too large!";
            }
        }

        if (!$error->status) {
            $message = Chatify::newMessage([
                'from_id' => Auth::guard('sanctum')->user()->id,
                'to_id' => $request['id'],
                'body' => htmlentities(trim($request['message']), ENT_QUOTES, 'UTF-8'),
                'sent_by' => 'admin',
                'attachment' => ($attachment) ? json_encode((object)[
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim($attachment_title), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);
            $messageData = Chatify::parseMessage($message);
            Chatify::push("private-chatify." . $request['id'], 'messaging', [
                'from_id' => Auth::guard('sanctum')->user()->id,
                'to_id' => $request['id'],
                'message' => Chatify::messageCard($messageData, true)
            ]);

            // $this->sendPushNotification("New Message!", $request['message'], $request['id']);
            $this->sendPushNotification("Admin", $request['message'], $request['id']);
        }

        // send the response
        return Response::json([
            'status' => '200',
            'error' => $error,
            'message' => Chatify::messageCard(@$messageData),
            'tempID' => $request['temporaryMsgId'],
        ]);
    }

    /**
     * fetch [user/group] messages from database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function fetch(Request $request)
    {
        $query = Chatify::fetchMessagesQuery($request['id'], Auth::guard('sanctum')->user()->id)->latest();
        $messages = $query->paginate($request->per_page ?? $this->perPage);
        $totalMessages = $messages->total();
        $lastPage = $messages->lastPage();
        $response = [
            'total' => $totalMessages,
            'last_page' => $lastPage,
            'last_message_id' => collect($messages->items())->last()->id ?? null,
            'messages' => '',
        ];

        // if there is no messages yet.
        if ($totalMessages < 1) {
            $response['messages'] = '<p class="message-hint center-el"><span>Say \'hi\' and start messaging</span></p>';
            return Response::json($response);
        }
        if (count($messages->items()) < 1) {
            $response['messages'] = '';
            return Response::json($response);
        }
        $allMessages = null;
        foreach ($messages->reverse() as $message) {
            $allMessages .= Chatify::messageCard(
                Chatify::parseMessage($message)
            );
        }
        $response['messages'] = $allMessages;
        return Response::json($response);
    }

    /**
     * Make messages as seen
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function seen(Request $request)
    {
        // make as seen
        $seen = Chatify::makeSeen($request['id']);
        // send the response
        return Response::json([
            'status' => $seen,
        ], 200);
    }

    /**
     * Get contacts list
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getContacts(Request $request)
    {
        // get all users that received/sent message from/to [Auth user]
        $users = Message::join('customer_infos',  function ($join) {
            $join->on('ch_messages.from_id', '=', 'customer_infos.id')
                ->orOn('ch_messages.to_id', '=', 'customer_infos.id');
        })
            ->where(function ($q) {
                $q->where('ch_messages.from_id', Auth::guard('sanctum')->user()->id)
                    ->orWhere('ch_messages.to_id', Auth::guard('sanctum')->user()->id);
            })
            // ->where('customers.id','!=',Auth::guard('sanctum')->user()->id)
            ->select('customer_infos.*', DB::raw('MAX(ch_messages.created_at) max_created_at'))
            ->orderBy('max_created_at', 'desc')
            ->groupBy('customer_infos.id')
            ->paginate($request->per_page ?? $this->perPage);

        $usersList = $users->items();

        if (count($usersList) > 0) {
            $contacts = '';
            foreach ($usersList as $user) {
                $contacts .= Chatify::getContactItem($user);
            }
        } else {
            $contacts = '<p class="message-hint center-el"><span>Your contact list is empty</span></p>';
        }

        return Response::json([
            'contacts' => $contacts,
            'total' => $users->total() ?? 0,
            'last_page' => $users->lastPage() ?? 1,
        ], 200);
    }

    /**
     * Update user's list item data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateContactItem(Request $request)
    {
        // Get user data
        $user = User::where('id', $request['user_id'])->first();
        if (!$user) {
            return Response::json([
                'message' => 'User not found!',
            ], 401);
        }
        $contactItem = Chatify::getContactItem($user);

        // send the response
        return Response::json([
            'contactItem' => $contactItem,
        ], 200);
    }

    /**
     * Put a user in the favorites list
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function favorite(Request $request)
    {
        $userId = $request['user_id'];
        // check action [star/unstar]
        $favoriteStatus = Chatify::inFavorite($userId) ? 0 : 1;
        Chatify::makeInFavorite($userId, $favoriteStatus);

        // send the response
        return Response::json([
            'status' => @$favoriteStatus,
        ], 200);
    }

    /**
     * Get favorites list
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function getFavorites(Request $request)
    {
        $favoritesList = null;
        $favorites = Favorite::where('user_id', Auth::guard('sanctum')->user()->id);
        foreach ($favorites->get() as $favorite) {
            // get user data
            $user = User::where('id', $favorite->favorite_id)->first();
            $favoritesList .= view('Chatify::layouts.favorite', [
                'user' => $user,
            ]);
        }
        // send the response
        return Response::json([
            'count' => $favorites->count(),
            'favorites' => $favorites->count() > 0
                ? $favoritesList
                : 0,
        ], 200);
    }

    /**
     * Search in messenger
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function search(Request $request)
    {
        $getRecords = null;
        $input = trim(filter_var($request['input']));
        $records = User::where('name', 'LIKE', "%{$input}%")
            ->paginate($request->per_page ?? $this->perPage);
        foreach ($records->items() as $record) {
            $getRecords .= view('Chatify::layouts.listItem', [
                'get' => 'search_item',
                'user' => Chatify::getUserWithAvatar($record),
            ])->render();
        }
        if ($records->total() < 1) {
            $getRecords = '<p class="message-hint center-el"><span>Nothing to show.</span></p>';
        }
        // send the response
        return Response::json([
            'records' => $getRecords,
            'total' => $records->total(),
            'last_page' => $records->lastPage()
        ], 200);
    }

    /**
     * Get shared photos
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function sharedPhotos(Request $request)
    {
        $shared = Chatify::getSharedPhotos($request['user_id']);
        $sharedPhotos = null;

        // shared with its template
        for ($i = 0; $i < count($shared); $i++) {
            $sharedPhotos .= view('Chatify::layouts.listItem', [
                'get' => 'sharedPhoto',
                'image' => Chatify::getAttachmentUrl($shared[$i]),
            ])->render();
        }
        // send the response
        return Response::json([
            'shared' => count($shared) > 0 ? $sharedPhotos : '<p class="message-hint"><span>Nothing shared yet</span></p>',
        ], 200);
    }

    /**
     * Delete conversation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteConversation(Request $request)
    {
        // delete
        $delete = Chatify::deleteConversation($request['id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    /**
     * Delete message
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteMessage(Request $request)
    {
        // delete
        $delete = Chatify::deleteMessage($request['id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    public function updateSettings(Request $request)
    {
        $msg = null;
        $error = $success = 0;

        // dark mode
        if ($request['dark_mode']) {
            $request['dark_mode'] == "dark"
                ? Admin::where('id', Auth::guard('sanctum')->user()->id)->update(['dark_mode' => 1])  // Make Dark
                : Admin::where('id', Auth::guard('sanctum')->user()->id)->update(['dark_mode' => 0]); // Make Light
        }

        // If messenger color selected
        if ($request['messengerColor']) {
            $messenger_color = trim(filter_var($request['messengerColor']));
            Admin::where('id', Auth::guard('sanctum')->user()->id)
                ->update(['messenger_color' => $messenger_color]);
        }
        // if there is a [file]
        if ($request->hasFile('avatar')) {
            // allowed extensions
            $allowed_images = Chatify::getAllowedImages();

            $file = $request->file('avatar');
            // check file size
            if ($file->getSize() < Chatify::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed_images)) {
                    // delete the older one
                    if (Auth::guard('sanctum')->user()->avatar != config('chatify.user_avatar.default')) {
                        $avatar = Auth::guard('sanctum')->user()->avatar;
                        if (Chatify::storage()->exists($avatar)) {
                            Chatify::storage()->delete($avatar);
                        }
                    }
                    // upload
                    $avatar = Str::uuid() . "." . $file->extension();
                    $update = Admin::where('id', Auth::guard('sanctum')->user()->id)->update(['avatar' => $avatar]);
                    $file->storeAs(config('chatify.user_avatar.folder'), $avatar, config('chatify.storage_disk_name'));
                    $success = $update ? 1 : 0;
                } else {
                    $msg = "File extension not allowed!";
                    $error = 1;
                }
            } else {
                $msg = "File size you are trying to upload is too large!";
                $error = 1;
            }
        }

        // send the response
        return Response::json([
            'status' => $success ? 1 : 0,
            'error' => $error ? 1 : 0,
            'message' => $error ? $msg : 0,
        ], 200);
    }

    /**
     * Set user's active status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function setActiveStatus(Request $request)
    {
        $activeStatus = $request['status'] > 0 ? 1 : 0;
        $status = User::where('id', Auth::guard('sanctum')->user()->id)->update(['active_status' => $activeStatus]);
        return Response::json([
            'status' => $status,
        ], 200);
    }

    // public function sendPushNotification($title, $message, $customerId = null, $imgUrl = null)
    // {
    //     $credentialsFilePath = $_SERVER['DOCUMENT_ROOT'] . '/assets/firebase/fcm-server-key.json';
    //     $client = new Google_Client();
    //     $client->setAuthConfig($credentialsFilePath);
    //     $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
    //     $client->refreshTokenWithAssertion();
    //     $token = $client->getAccessToken();
    //     $access_token = $token['access_token'];
    //     $project_id = env('APP_FCM_PROJECT_ID');

    //     $url = "https://fcm.googleapis.com/v1/projects/".$project_id."/messages:send";        
    //     // Fetch user's FCM tokens
    //     // $fcmTokens = FcmTokenKey::where('customer_id', $customerId)
    //     // ->orderBy('id', 'desc')
    //     // ->pluck('fcm_token_key');

    //     $customer = Customer::where('id', $customerId)->first();

    //     $notifications = [
    //         'title' => $title,
    //         'body' => $message,
    //     ];

    //     $dataPayload = [
    //         'message_id' => "1"
    //     ];

    //     if ($imgUrl) {
    //         $notifications['image'] = $imgUrl;
    //     }

    //     if ($customer) {
    //         // foreach ($fcmTokens as $fcmKey) {
    //             $data = [
    //                 'token' => $customer->fcm_token_key,
    //                 'notification' => $notifications,
    //                 'data'         => $dataPayload,
    //                 'apns' => [
    //                     'headers' => [
    //                         'apns-priority' => '10',
    //                     ],
    //                     'payload' => [
    //                         'aps' => [
    //                             'sound' => 'default',
    //                         ]
    //                     ],
    //                 ],
    //                 'android' => [
    //                     'priority' => 'high',
    //                     'notification' => [
    //                         'sound' => 'default',
    //                     ]
    //                 ],
    //             ];

    //             $response = Http::withHeaders([
    //                 'Authorization' => "Bearer $access_token",
    //                 'Content-Type' => "application/json"
    //             ])->post($url, [
    //                 'message' => $data
    //             ]);

    //             return true;
    //         }
    //     // }
    // }

    public function sendPushNotification($title, $message, $customerId = null, $imgUrl = null)
    {
        $credentialsFilePath = $_SERVER['DOCUMENT_ROOT'] . '/assets/firebase/fcm-server-key.json';
        $client = new Google_Client();
        $client->setAuthConfig($credentialsFilePath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->refreshTokenWithAssertion();
        $token = $client->getAccessToken();
        $access_token = $token['access_token'];
        $project_id = env('APP_FCM_PROJECT_ID');

        $url = "https://fcm.googleapis.com/v1/projects/".$project_id."/messages:send";        

        $customer = CustomerInfo::with('fcmTokens')->find($customerId);

        if (!$customer || $customer->fcmTokens->isEmpty()) {
            return false;
        }

        $notifications = [
            'title' => $title,
            'body' => $message,
        ];

        if ($imgUrl) {
            $notifications['image'] = $imgUrl;
        }

        $dataPayload = [
            'message_id' => "1"
        ];

        foreach ($customer->fcmTokens as $token) {
            $data = [
                'token' => $token->fcm_token_key,
                'notification' => $notifications,
                'data' => $dataPayload,
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ]
                    ],
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                    ]
                ],
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer $access_token",
                'Content-Type' => "application/json"
            ])->post($url, [
                'message' => $data
            ]);
        }

        return true;
    }

}