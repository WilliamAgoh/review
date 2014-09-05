<?php

class DocumentCon extends \BaseController {

	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create($submission_id)
	{
        $submission = Submission::find($submission_id);
        return View::make('submission.file.create')->withSubmission($submission);
	}


	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store($submission_id)
	{
        $rules = array(
            'document' => 'required|max:10000000|min:1|mimes:pdf,doc,docx',
            'camera_ready' => 'boolean',
            'author_can_read' => 'boolean',
            'reviewer_can_read' => 'boolean',
            'all_can_read' => 'boolean',
            'attached_to' => 'required|integer',
            'user_id' => 'required|integer',
        );

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            return Redirect::route('submission.file')->withErrors($validator);
        }

        $document = Input::file('document');
        $file = new File;
        $file->author_can_read = true;

        // Sanitize
        $name = $document->getClientOriginalName();
        $name = pathinfo($name)['filename'];
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9]+/', '_', $name);
        $name = ltrim($name, '_');
        $name = "$name." . $document->getClientExtension();

        $file->name = $name;
        $file->saved_name = uniqueid() . '/' . $file->name;
        if (!$file->move('uploads/', $file->saved_name)) {
            App::abort(500);
        }

        $file->save();
        return Redirect::route('submission.show', array($submission_id));
	}


	public function confirmDeleteDocument($document_id)
	{
        Session::keep('previous');
        $document = Document::findOrFail($document_id);
        // FIXME Need to check permissions
        return View::make('author.confirm_delete_document')
            ->withDocument($document);
	}

    public function deleteDocument($document_id)
    {
        $document = Document::findOrFail($document_id);
        $document->delete();
        return Redirect::to(Session::get('previous'));
    }


    public function download($document_id)
    {
        $user = Auth::user();
        $document = Document::find($document_id);

        $container = $document->container;

        if ($container instanceof Submission)
        {
            $submission = $container;
            $category = $submission->category;
            $can_download = false;

            if ($user->is_chair_of($category))
            {
                $can_download = true;
            }
            else if ($user->is_author_of($submission))
            {
                $can_download = true;
            }
            else if ($user->is_reviewer_of($submission))
            {
                if ($document->is_for_reviewers)
                {

                    if ($submission->is_status_effectively(array('reviewing', 'finalizing', 'final')))
                    {
                        $can_download = true;
                    }

                }
            }
        }
        elseif ($container instanceof Category)
        {
            $category = $container;
            $can_download = true;
        }
        elseif ($container instanceof Review)
        {
            $review = $container;
            $submission = $review->submission;
            $category = $submission->category;
            if ($user->is_chair_of($category))
            {
                $can_download = true;
            }
            else if ($user->is_owner_of($review))
            {
                $can_download = true;
            }
            else if ($user->is_author_of($submission))
            {
                if ($submission->is_for_authors)
                {
                    if ($submission->is_status_effectively('finalizing', 'final'))
                    {
                        $can_download = true;
                    }
                }
            }
        }

        if ($can_download)
        {
            $document->usersDownloaded()->attach($user->id);
            return Response::download('uploads/'.$document->saved_name, $document->name);
        }

        App::abort(403, 'Unauthorized action.');
    }

    public function upload()
    {
        $rules = array(
            'document' => 'required|max:10000000|min:1|mimes:pdf,doc,docx',
            'container_id' => 'required|integer',
            'container_type' => array(
                'required', 'regex:/^(Submission)|(Category)|(Review)$/'),
        );
        $validator = Validator::make(Input::all(), $rules);
        if ($validator->fails())
        {
            return Redirect::to(Session::get('previous'))->withErrors($validator);
        }

        // $container = Container::findOrFail($container_id)
        $container_type = Input::get('container_type');
        $callback = array($container_type, 'findOrFail');
        $params = array(Input::get('container_id'));
        $container = call_user_func($callback, $params)->first();

        $user = Auth::user();
        $file = Input::file('document');

        // Create a blank Document entry. We need its id to generate the 
        // filename.
        $document = new Document;
        $document->save();

        $container_code = substr($container_type, 0, 1);
        $container_id = $container->id;
        $document_id = $document->id;
        $extension = $file->getClientOriginalExtension();
        if ($container_type == 'Category')
        {
            $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $name = preg_replace('/[^a-zA-Z_-]+/', '_', $name);
            $document->name = "$name.$extension";
        }
        else
        {
            $document->name = "$container_type-$container_id-$document_id.$extension";
            $document->saved_name = uniqid() . '/' . $document->name;
        }

        $file->move('uploads/'.dirname($document->saved_name), basename($document->saved_name));

        $document->container()->associate($container);
        $document->save();

        return Redirect::to(Session::get('previous'));
	}

}
