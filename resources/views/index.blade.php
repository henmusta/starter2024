<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tester</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
</head>
<body>
<header class="header col-md-12 text-center" style="padding-top:50px;">
 <div class="header-inner container">
    <div class="row">
        <div class="col-md-12">
            <div class="multi-collapse collapse show" id="multiCollapseExample2" style="">
                <div class="card border shadow-none card-body text-muted mb-0">
                    <div class="row">
                        <div id="bulan" class="col-md-4">
                            <div class="mb-3">
                                <label for="select2Bulan">Filter A<span class="text-danger"></span></label>
                                <select style="width: 100% !important;" id="select2Bulan" class="js-example-basic-multiple" name="bulan">
                                </select>
                            </div>
                        </div>
                        <div id="tahun" class="col-md-4">
                            <div class="mb-3">
                                <label for="select2Bulan">Filter B<span class="text-danger"></span></label>
                                <select style="width: 100% !important;" id="select2Tahun" class="js-example-basic-multiple" name="tahun">
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2 text-end" style="padding-top:30px;">
                            <a id="terapkan_filter" class="btn btn-success">
                                Preview
                                <i class="fas fa-align-justify"></i>
                            </a>
                        </div>
                    </div>






                </div>
            </div>
        </div>
    </div>
 </div>
</header>
<div class="container">

    @include('card.inquirylistprakarsa.card')

</div>    
<input type="hidden" id="get-url" value="{{ config('app.url') }}">
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script>
$(document).ready(function () {
    $("#terapkan_filter").click(function() {
            var url = document.getElementById("get-url").value;
            alert();
            var data = {
              
            };
            let btnSubmit = $("#terapkan_filter");
            let btnSubmitHtml = btnSubmit.html();
            $.ajax({
                beforeSend: function () {
                    btnSubmit.addClass("disabled").html("<span aria-hidden='true' class='spinner-border spinner-border-sm' role='status'></span> Loading ...").prop("disabled", "disabled");
                },
                cache: false,
                processData: false,
                contentType: 'application/json',
                type: "POST",
                url: url,
                data: JSON.stringify(data),
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(res) {
                    btnSubmit.removeClass("disabled").html(btnSubmitHtml).removeAttr("disabled");
                    console.log(res);
                    $("#report").html(res);
                }
            });
        });
});
<script>

</body>
</html>