/*
 * Copyright (c) $originalComment.match("Copyright \(c\) (\d+)", 1, "-", "$today.year")2022. Elman Boyazitov flobus@mail.ru
 */

let app = {
    fotorama: null, //Объект fotorama - слайдера. Создается и уничтожается при открытии и закрытии попапа товара

    //Создание кода меню на основе входящего массива
    createMenu: function (array)
    {
        let menu = '<ul>';
        for (let key in array) {
            let row = array[key];
            menu += '<li' + (row.isParent > 0 ? ' class="isParent"' : '') + '>' +
                '<a href="/' + row.id + '" data-count="' + row.products + '">' + row.name + ' (' + row.products + ')</a></li>';
        }
        menu += '</ul>';
        return menu;
    },

    //Имитация загрузки товаров
    loadingDummy: function(count){
        let dummy = '<div class="productRowDummy">\n' +
            '        <div class="dummy1 loading"></div>\n' +
            '        <div class="dummy2 loading"></div>\n' +
            '        <div class="dummy3 loading"></div>\n' +
            '    </div>';

        $("main").html(dummy.repeat(count));
    },

    //Создание кода листинга товаров
    createShowcase: function(categoryID, array, title)
    {
        let content = '<h1>' + title + '</h1>';
        array = $.isArray(array) ? array : [array];
        for (let key in array) {
            let row = array[key];
            content += '<div class="productRow"><h2>' + row.Name + '</h2>' +
                '<img src="' + row.Picture + '">' +
                '<div class="price">' + row.Price + '</div>' +
                '<a href="' + categoryID + '/' + row.Id + '">Подробнее</a></div>';
        }
        return content;
    },

    //Сообщение о пустой категории вместо листинга
    nothingFind: function(title){
        $("main").html('<div class="productRow"><h1>' + title + '</h1>' +
            '<h2>В этом разделе нет товаров.</h2></div>');
    },

    //Запрос списка категорий методом ajax
    //Затем инициализация пунктов меню
    getCategories: function (parentID, container)
    {
        $.post("/Core/Ajax.php",
            {"action": "getCategories", "parentID": parentID},
            function (data) {
                let answer = JSON.parse(data);
                if (answer.result) {
                    let goodsObj = answer.data,
                        menu = app.createMenu(goodsObj);
                    container.html(menu);
                    $("nav li a").off("click").on("click", function (e) {
                        e.preventDefault();
                        let parentLi = $(this).closest("li");

                        $("nav li").removeClass("current");
                        parentLi.addClass("current");

                        if($("nav").hasClass("open")){
                            $("#mobile_menu").click();
                        }

                        if (parentLi.hasClass("opened")) {
                            parentLi.removeClass("opened");
                            $(this).next("div").remove();
                        } else {
                            let url = $(this).attr("href"),
                                catID = url.split("/")[1],
                                count = $(this).data("count"),
                                title = $(this).text().replace(/\(\d+\)/, '');
                            document.title = "Витрина: " + title;
                            parentLi.addClass("opened");
                            $(this).next("div").remove();
                            $(this).after("<div/>");
                            app.getCategories(catID, $(this).next("div"));
                            app.getProducts(catID, title, count);
                        }

                    });

                    $("#loader_wrap").hide();
                }
            }
        );
    },

    //Запрос списка товаров из категории указанной в categoryID методом ajax
    //Затем инициализация пунктов листинга товаров
    getProducts: function(categoryID, title, count)
    {
        $("#loader_wrap").show();
        app.loadingDummy(count);
        $.post("/Core/Ajax.php",
            {"action": "getProducts", "categoryID": categoryID},
            function (data) {
                let answer = JSON.parse(data),
                    $main = $("main");
                if (answer.result) {
                    let content = answer.data;
                    if(content.length > 0){
                        $main.html(app.createShowcase(categoryID, content, title));
                        $('html, body').animate({scrollTop: 0}, 400);
                        $(".productRow").on("click", function(e){
                            e.preventDefault();
                            let prodID = $(this).find("a").attr("href").split("/")[1];

                            app.showPopup(prodID);
                        });
                    }else{
                        app.nothingFind(title);
                    }

                }else{
                    app.nothingFind(title);
                }
                $("#loader_wrap").hide();
            }
        )
    },

    //Инициализация кнопки "Обновить базу данных". Шаг 1 - получение не пустых категорий
    initRefresh: function()
    {

        $("header .button").on("click", function(e){
            e.preventDefault();

            let $this = $(this);

            $("#loader_wrap").show();
            $this.addClass("load");

            $.post("/Core/Ajax.php",
                {"action": "refresh"},
                function (data) {
                    let answer = JSON.parse(data);
                    $("#loading_log").addClass("show").html(answer.data);
                    $("#loader_wrap").hide();
                }
            )

        })
    },

    //Шаг 2 и далее - получение следующей категории для загрузки товаров
    loadNextCategory: function()
    {
        $.post("/Core/Ajax.php",
            {"action": "loadNext"},
            function (data) {
                let answer = JSON.parse(data);
                if(answer.result && answer.data.length > 10) {
                    $("#loading_log")
                        .append("<br>" + answer.data)
                        .scrollTop(9000000);
                }
            }
        )
    },

    //Шаг последний - завершение загрузки
    endLoadCategory: function ()
    {
        $("#loading_log").fadeOut("slow", function(){
            $(this).empty().removeClass("show");
            app.getCategories(0, $("nav"));
            alert("Обновление завершено.");
        });
        $("header .button").removeClass("load");

    },

    //Создание и наполнение попапа товара
    showPopup: function (productID)
    {
        let $popup = $("#popup"), content, photos;

        $("#loader_wrap").show();

        $.post("/Core/Ajax.php",
            {"action": "getProduct", "productID": productID},
            function (data) {
                let answer = JSON.parse(data),
                    content = answer.data[0],
                    photos = [{ img: content.Picture, thumb: content.Picture}],
                    $params = $("#popup #description"),
                    $fotoramaDiv, gallery = '<img src="' + content.Picture + '">';

                $popup.find("h1").text(content.Name);

                if(content.hasOwnProperty("Params") && content.Params != null){
                    let paramsArr = [],
                        pars = content.Params;
                    if($.isArray(pars)) {
                        for (let i in pars) {
                            paramsArr.push(pars[i].name + ": " + pars[i].value);
                        }
                    }else{
                        paramsArr.push(pars.name + ": " + pars.value);
                    }
                    $params.html(paramsArr.join("<br>"));
                }else{
                    $params.empty();
                }

                $popup.find(".price").text('Цена: ' + content.Price);

                if($.isArray(content.Fotos)) {
                    for (let img in content.Fotos) {
                        photos.push({img: content.Fotos[img], thumb: content.Fotos[img]});
                        gallery += '<img src="' + content.Fotos[img] + '">';
                    }
                }else{
                    gallery += '<img src="' + content.Fotos + '">';
                }


                $popup.show();
                setTimeout(function () {
                    $popup.removeClass("start").addClass("end");
                    $("#popup_wrap").show();
                    $("#loader_wrap").hide();
                    setTimeout(function () {
                        $("#popup_content").css("opacity", 1);

                        $(".fotorama").html(gallery);
                        $fotoramaDiv = $(".fotorama").fotorama({
                            width: 700,
                            maxwidth: '100%',
                            ratio: 16/9,
                            allowfullscreen: true,
                            nav: 'thumbs'
                        });
                        setTimeout(function(){
                            app.fotorama = $fotoramaDiv.data('fotorama');
                        }, 600);
                    }, 500);

                }, 500);

                //Закрытие попапа по клику на крестике или заднем фоне
                $("#popup_close, #popup_wrap").off("click").on("click", function () {

                    $popup.fadeOut(100, function () {

                        $popup.removeClass("end").addClass("start");
                        $("#popup_content").css("opacity", 0);
                        $("#popup_wrap").hide();
                        app.fotorama.destroy();
                        $(".fotorama--hidden").remove();
                        $(".fotorama").empty();
                    });
                });
            });


    },

    //Инициализация мобильного меню. Используется настольное меню, меняется позиционирование
    mobileMenu: function()
    {
        $("#mobile_menu").on("click", function(){
            let $nav = $("nav");

            if($nav.hasClass("open")){
                $(this).removeClass("open");
                $nav.removeClass("open");
                $("main").show();
            }else {
                $(this).addClass("open");
                $nav.addClass("open");
                $("main").hide();
            }
        });
    }
}

//Инициализация страницы, кнопки "Обновить базу данных" и мобильного меню, загрузка корневых категорий и
// клик по разделу "Пластиковые сертификаты" (что бы не было пусто справа).
$(document).ready(function () {
    app.getCategories(0, $("nav"));
    app.initRefresh();
    app.mobileMenu();
    setTimeout(function(){
        $("nav ul li a[href='/189']").click();
    }, 500);

});