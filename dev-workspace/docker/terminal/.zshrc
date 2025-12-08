export LANG='en_US.UTF-8'
export LANGUAGE='en_US:en'
export LC_ALL='en_US.UTF-8'
[ -z "xterm-256color" ] && export TERM=xterm

##### Zsh/Oh-my-Zsh Configuration
export ZSH="$HOME/.oh-my-zsh"

ZSH_THEME="ys"
plugins=(git asdf wp-cli )

git config --global --add safe.directory /project

source $ZSH/oh-my-zsh.sh
