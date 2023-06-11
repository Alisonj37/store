let array = [

    {
        categoria: "Paintings",
        nome: "Montagem video",
        preço: "R$ 400.00",
        imagem: "assets/img/paintings/mt video.jpg",

    },
    {
        categoria: "Paintings",
        nome: "Curso Marketing",
        preço: "R$ 300.00",
        imagem: "assets/img/paintings/marketing.jpg",

    },
    {
        categoria: "Paintings",
        nome: "Curso Programaçao",
        preço: "R$ 500.00",
        imagem: "assets/img/paintings/programa.jpg",

    },
    {
        categoria: "Paintings",
        nome: "Web Designer",
        preço: "R$ 100.00",
        imagem: "assets/img/paintings/designecologist.jpg",

    },
    {
        categoria: "Actions figure",
        nome: "Blog",
        preço: "R$ 800.00",
        imagem: "assets/img/actions figure/blog.jpg",

    },
    {
        categoria: "Actions figure",
        nome: "Excel",
        preço: "R$ 900.00",
        imagem: "assets/img/actions figure/pexels-thisisengineering-3861964.jpg",
    },
    {
        categoria: "Actions figure",
        nome: "Finance",
        preço: "R$ 2200.00",
        imagem: "assets/img/actions figure/finance.jpg",
    },
    {
        categoria: "Actions figure",
        nome: "Depanage",
        preço: "R$ 100.00",
        imagem: "assets/img/actions figure/depanage.jpg",
    }

];

let sectionPainting = document.getElementsByClassName("paint");
let sectionAction = document.getElementsByClassName("action");

for(let i = 0; 1< array.length; i++){
    let elementList = document.createElement("li");
    let image = document.createElement("img");
    let nome = document.createElement("p");
    let preço = document.createElement("span");

    image.src = array[i].imagem;
    nome.innerText = array[i].nome;
    preço.innerText = array[i].preço;

    elementList.append(image , nome , preço);

    if(array[i].categoria == "Paintings"){
        sectionPainting[0].appendChild(elementList);
    }else{
        sectionAction[0].appendChild(elementList);
    }

}