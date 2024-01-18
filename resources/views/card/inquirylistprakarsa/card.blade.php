<div class="row">
    @foreach($data->responseData as $val)
  
    <div class="col-sm-6" style="padding:10px;">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">{{$val->nik}}</h5>
          <p class="card-text">{{$val->status_desc}}</p>
          <a href="#" class="btn btn-primary">Detail</a>
        </div>
      </div>
    </div>
    @endforeach
  </div>