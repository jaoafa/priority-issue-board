new Vue({
    el: '#app',
    vuetify: new Vuetify(),
    data: {
        message: "hoge",
        loading: false,
        repo: "MyMaid4",
        repos: [],
        headers: [
            {
                text: '#',
                align: 'left',
                sortable: false,
                value: 'number'
            },
            {
                text: 'タイトル',
                align: 'left',
                sortable: false,
                value: 'title'
            },
            {
                text: '投稿者',
                align: 'left',
                sortable: false,
                value: 'author'
            },
            {
                text: '+1',
                align: 'left',
                sortable: true,
                value: 'upvote',
                sort: (a, b) => a.count - b.count
            },
            {
                text: '-1',
                align: 'left',
                sortable: true,
                value: 'downvote',
                sort: (a, b) => a.count - b.count
            },
            {
                text: '見る',
                align: 'left',
                sortable: false,
                value: 'see'
            },
        ],
        items: [],
        search: "",
        dialog: false,
        displaylabel: false
    },
    mounted() {
        this.getRepos()

        const url = new URL(window.location.href);
        const params = url.searchParams;
        if (params.has("repo")) {
            this.repo = params.get('repo')
        }

        this.getIssues()
    },
    methods: {
        getRepos() {
            this.loading = true
            axios.get(`api.php`, {
                params: {
                    repo: this.repo,
                    action: "get-repos"
                }
            })
                .then(response => {
                    this.repos = response.data.data
                    this.loading = false
                })
                .catch(error => {
                    console.error(error)
                    alert("通信中にエラーが発生しました。\nしばらく待ってからもう一度お試しください。\n\n" + error.message + (error.response ? "\n" + error.response.data.data : ""))
                })
        },
        getIssues() {
            if (this.repo === null) {
                return;
            }
            history.replaceState('', '', '?repo=' + this.repo);

            this.loading = true
            axios.get(`api.php`, {
                params: {
                    repo: this.repo,
                    action: "get-issues"
                }
            })
                .then(response => {
                    this.items = response.data.data
                    this.loading = false
                })
                .catch(error => {
                    console.error(error)
                    console.log(error.response)
                    alert("通信中にエラーが発生しました。\nしばらく待ってからもう一度お試しください。\n\n" + error.message + (error.response ? "\n" + error.response.data.data : ""))
                })
        },
        upvote(item) {
            if (item.upvote.voted) {
                this.resetvote(item)
                return
            }
            this.items = this.items.map(current => current.number === item.number ? {
                ...current,
                upvote: {
                    ...current.upvote,
                    loading: true,
                    voted: !current.upvote.voted
                },
                downvote: {
                    ...current.downvote,
                    count: current.downvote.voted ? current.downvote.count - 1 : current.downvote.count,
                    voted: false
                },
            } : current)

            const params = new URLSearchParams()
            params.append("number", item.number)
            axios.post(`api.php?repo=${this.repo}&action=up`, params)
                .then(() => {
                    this.items = this.items.map(current => current.number === item.number ? {
                        ...current,
                        upvote: {
                            ...current.upvote,
                            count: current.upvote.count + 1,
                            loading: false,
                        },
                    } : current)
                })
                .catch(error => {
                    console.error(error)
                    alert("通信中にエラーが発生しました。\nしばらく待ってからもう一度お試しください。\n\n" + error.message + (error.response ? "\n" + error.response.data.data : ""))
                })
        },
        downvote(item) {
            if (item.downvote.voted) {
                this.resetvote(item)
                return
            }
            this.items = this.items.map(current => current.number === item.number ? {
                ...current,
                upvote: {
                    ...current.upvote,
                    count: current.upvote.voted ? current.upvote.count - 1 : current.upvote.count,
                    voted: false
                },
                downvote: {
                    ...current.downvote,
                    loading: true,
                    voted: !current.downvote.voted
                },
            } : current)

            const params = new URLSearchParams()
            params.append("number", item.number)
            axios.post(`api.php?repo=${this.repo}&action=down`, params)
                .then(() => {
                    this.items = this.items.map(current => current.number === item.number ? {
                        ...current,
                        downvote: {
                            ...current.downvote,
                            count: current.downvote.count + 1,
                            loading: false,
                        },
                    } : current)
                })
                .catch(error => {
                    console.error(error)
                    alert("通信中にエラーが発生しました。\nしばらく待ってからもう一度お試しください。\n\n" + error.message + (error.response ? "\n" + error.response.data.data : ""))
                })
        },
        resetvote(item) {
            const votedType = item.upvote.voted ? "up" : "down"
            this.items = this.items.map(current => current.number === item.number ? {
                ...current,
                upvote: {
                    ...current.upvote,
                    loading: votedType === "up" ? true : false,
                },
                downvote: {
                    ...current.downvote,
                    loading: votedType === "down" ? true : false,
                },
            } : current)

            const params = new URLSearchParams()
            params.append("number", item.number)
            axios.post(`api.php?repo=${this.repo}&action=reset`, params)
                .then(() => {
                    this.items = this.items.map(current => current.number === item.number ? {
                        ...current,
                        upvote: {
                            ...current.upvote,
                            count: votedType === "up" ? current.upvote.count - 1 : current.upvote.count,
                            voted: false,
                            loading: false,
                        },
                        downvote: {
                            ...current.downvote,
                            count: votedType === "down" ? current.downvote.count - 1 : current.downvote.count,
                            voted: false,
                            loading: false,
                        },
                    } : current)
                })
                .catch(error => {
                    console.error(error)
                    alert("通信中にエラーが発生しました。\nしばらく待ってからもう一度お試しください。\n\n" + error.message + (error.response ? "\n" + error.response.data.data : ""))
                })
        },
        getTextColor(hexcolor) {
            const r = parseInt(hexcolor.substr(0, 2), 16);
            const g = parseInt(hexcolor.substr(2, 2), 16);
            const b = parseInt(hexcolor.substr(4, 2), 16);

            return ((((r * 299) + (g * 587) + (b * 114)) / 1000) < 128) ? "white" : "black";
        }
    },
    watch: {
        repo: function () {
            this.getIssues()
        }
    }
})